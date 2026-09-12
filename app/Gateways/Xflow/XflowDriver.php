<?php

namespace App\Gateways\Xflow;

use App\Gateways\Contracts\GatewayDriver;
use App\Services\Xflow\XflowHttpClient;
use RuntimeException;

class XflowDriver implements GatewayDriver
{
    public function __construct(
        private readonly XflowHttpClient $client = new XflowHttpClient(),
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->client->request($credentials, 'GET', 'company');
        } catch (\Throwable) {
            return false;
        }

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @param  array<string, mixed>  $options
     * @return array{transaction_id: string, qrcode?: string|null, copy_paste?: string|null, raw?: array, metadata?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $amountCents = max(1, (int) round($amount * 100));
        $externalId = mb_substr(trim($externalId), 0, 120);
        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }
        $email = trim((string) ($consumer['email'] ?? ''));
        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));

        $body = [
            'amountCents' => $amountCents,
            'customer' => [
                'name' => mb_substr($name, 0, 120),
                'email' => $email !== '' ? $email : 'cliente@pedido.local',
                'document' => $document !== '' ? $document : '00000000000',
            ],
            'description' => 'Pedido #'.$externalId,
            'external_reference' => $externalId,
        ];

        $response = $this->client->request(
            $credentials,
            'POST',
            'charges',
            $body,
            ['Idempotency-Key' => 'order-'.$externalId]
        );

        if (! $response->successful()) {
            throw new RuntimeException('Xflow: '.$this->client->errorMessage($response));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Xflow: resposta inválida ao criar cobrança.');
        }

        $charge = XflowHttpClient::unwrapObject($json);
        $transactionId = trim((string) ($charge['id'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('Xflow: cobrança criada sem id.');
        }

        $pix = is_array($charge['pix'] ?? null) ? $charge['pix'] : [];
        $copyPaste = is_string($pix['copyPaste'] ?? null) ? $pix['copyPaste'] : null;
        $qrcode = is_string($pix['qrCodeBase64'] ?? null) ? $pix['qrCodeBase64'] : null;

        $livemode = $charge['livemode'] ?? null;

        return [
            'transaction_id' => $transactionId,
            'qrcode' => $qrcode !== '' ? $qrcode : null,
            'copy_paste' => $copyPaste !== '' ? $copyPaste : null,
            'raw' => $json,
            'metadata' => array_filter([
                'xflow_livemode' => is_bool($livemode) ? $livemode : null,
                'xflow_request_id' => $this->client->requestId($response),
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        try {
            $response = $this->client->request($credentials, 'GET', 'charges/'.rawurlencode($transactionId));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $charge = XflowHttpClient::unwrapObject($json);

        return $this->mapChargeStatus($charge['status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{available: int|float, reserved?: int|float, currency?: string, livemode?: bool}
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $response = $this->client->request($credentials, 'GET', 'balance');
        if (! $response->successful()) {
            throw new RuntimeException('Xflow: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Xflow: resposta de saldo inválida.');
        }

        return XflowHttpClient::unwrapObject($json);
    }

    /**
     * Saque PIX (POST /transfers). Valores em centavos.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, pending_approval?: bool, error?: string, raw?: array}
     */
    public function createTransfer(
        array $credentials,
        int $amountCents,
        string $pixKey,
        string $pixKeyType,
        string $idempotencyKey,
        ?string $message = null,
    ): array {
        $pixKey = trim($pixKey);
        $amountCents = max(1, $amountCents);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'Xflow: chave PIX de destino ausente.'];
        }

        $body = [
            'amount' => $amountCents,
            'pix_key' => $pixKey,
            'pix_key_type' => self::normalizePixKeyType($pixKeyType, $pixKey),
        ];
        if (is_string($message) && trim($message) !== '') {
            $body['message'] = mb_substr(trim($message), 0, 140);
        }

        try {
            $response = $this->client->request(
                $credentials,
                'POST',
                'transfers',
                $body,
                ['Idempotency-Key' => mb_substr($idempotencyKey !== '' ? $idempotencyKey : 'saque-'.uniqid('', true), 0, 120)],
                40
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Xflow: falha na requisição de saque.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Xflow: '.$this->client->errorMessage($response)];
        }

        $json = $response->json();
        $data = is_array($json) ? XflowHttpClient::unwrapObject($json) : [];
        $id = trim((string) ($data['id'] ?? ''));

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => $id !== '' ? $id : null,
            'pending_approval' => (bool) ($data['pending_approval'] ?? false),
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransferStatus(string $transferId, array $credentials): ?string
    {
        $transferId = trim($transferId);
        if ($transferId === '') {
            return null;
        }

        try {
            $response = $this->client->request($credentials, 'GET', 'transfers/'.rawurlencode($transferId));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $data = XflowHttpClient::unwrapObject($json);

        return self::mapTransferStatus($data['status'] ?? null);
    }

    public static function mapTransferStatus(mixed $status): ?string
    {
        if (! is_string($status) && ! is_int($status)) {
            return null;
        }
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'completed', 'paid', 'success', 'succeeded' => 'paid',
            'failed', 'canceled', 'cancelled', 'refused', 'rejected' => 'failed',
            'pending', 'processing', 'queued' => 'pending',
            default => null,
        };
    }

    public static function normalizePixKeyType(string $type, string $key): string
    {
        $mapped = match (strtolower(trim($type))) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'phone', 'telefone' => 'PHONE',
            'evp', 'random', 'aleatoria', 'aleatória' => 'EVP',
            default => strtoupper(trim($type)),
        };
        if (in_array($mapped, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
            return $mapped;
        }
        if (str_contains($key, '@')) {
            return 'EMAIL';
        }
        $digits = preg_replace('/\D/', '', $key) ?: '';
        if (strlen($digits) === 11) {
            return 'CPF';
        }
        if (strlen($digits) === 14) {
            return 'CNPJ';
        }

        return 'EVP';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{payment_token: string, card_mask?: string}  $card
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new RuntimeException('Xflow não suporta pagamento com cartão.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new RuntimeException('Xflow não suporta boleto.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function isLiveCredentials(array $credentials): bool
    {
        $public = strtolower(trim((string) ($credentials['public_key'] ?? '')));
        $secret = strtolower(trim((string) ($credentials['secret_key'] ?? '')));

        return str_starts_with($public, 'pk_live_') || str_starts_with($secret, 'sk_live_');
    }

    public static function mapChargeStatus(mixed $status): ?string
    {
        if (! is_string($status) && ! is_int($status)) {
            return null;
        }
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'paid' => 'paid',
            'pending', 'under_review', 'blocked' => 'pending',
            'expired', 'canceled', 'cancelled', 'refunded' => 'cancelled',
            default => null,
        };
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document) ?? '';

        return is_string($digits) ? $digits : '';
    }
}
