<?php

namespace App\Models;

use App\Support\UazapiCartRecoverySteps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UazapiInstance extends Model
{
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_HIBERNATED = 'hibernated';

    public const EVENT_CART_RECOVERY = 'cart_recovery';

    public const EVENT_PIX_GENERATED = 'pix_generated';

    public const EVENT_CAMPAIGN = 'campaign';

    protected $fillable = [
        'tenant_id',
        'server_url',
        'instance_id',
        'instance_name',
        'instance_token',
        'webhook_secret',
        'status',
        'phone',
        'profile_name',
        'qrcode',
        'paircode',
        'is_active',
        'cart_recovery_enabled',
        'pix_recovery_enabled',
        'send_product_image',
        'cart_recovery_steps',
        'pix_recovery_steps',
        'message_pix',
        'last_error',
        'connected_at',
        'webhook_synced_at',
        'label_map',
    ];

    protected function casts(): array
    {
        return [
            'instance_token' => 'encrypted',
            'is_active' => 'boolean',
            'cart_recovery_enabled' => 'boolean',
            'pix_recovery_enabled' => 'boolean',
            'send_product_image' => 'boolean',
            'cart_recovery_steps' => 'array',
            'pix_recovery_steps' => 'array',
            'label_map' => 'array',
            'connected_at' => 'datetime',
            'webhook_synced_at' => 'datetime',
        ];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(UazapiMessageDispatch::class);
    }

    public static function forTenant(int $tenantId): ?self
    {
        return static::query()->where('tenant_id', $tenantId)->first();
    }

    public static function firstOrNewForTenant(int $tenantId): self
    {
        $existing = static::forTenant($tenantId);
        if ($existing) {
            return $existing;
        }

        $instance = new static;
        $instance->tenant_id = $tenantId;
        $instance->webhook_secret = Str::lower(Str::random(48));
        $instance->status = self::STATUS_DISCONNECTED;
        $instance->is_active = true;
        $instance->cart_recovery_enabled = false;
        $instance->pix_recovery_enabled = false;
        $instance->send_product_image = true;
        $instance->cart_recovery_steps = UazapiCartRecoverySteps::defaults();
        $instance->pix_recovery_steps = UazapiCartRecoverySteps::pixDefaults();
        $instance->message_pix = (string) (config('uazapi.defaults.messages.pix_generated') ?? '');

        return $instance;
    }

    public function hasCredentials(): bool
    {
        return is_string($this->server_url) && trim($this->server_url) !== ''
            && is_string($this->instance_token) && trim($this->instance_token) !== '';
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && $this->hasCredentials();
    }

    public function canSendRecovery(): bool
    {
        return $this->is_active && $this->isConnected();
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public function cartRecoverySteps(): array
    {
        return UazapiCartRecoverySteps::forInstance($this);
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public function pixRecoverySteps(): array
    {
        return UazapiCartRecoverySteps::forPixInstance($this);
    }

    public function pixMessageTemplate(): string
    {
        $message = trim((string) ($this->message_pix ?? ''));
        if ($message !== '') {
            return $message;
        }

        return (string) (config('uazapi.defaults.messages.pix_generated') ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeQr = true): array
    {
        $connecting = in_array($this->status, [self::STATUS_CONNECTING, self::STATUS_DISCONNECTED], true);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'phone' => $this->phone,
            'profile_name' => $this->profile_name,
            'qrcode' => $includeQr && $connecting ? $this->qrcode : null,
            'paircode' => $includeQr && $connecting ? $this->paircode : null,
            'server_url' => (string) ($this->server_url ?? ''),
            'has_token' => is_string($this->instance_token) && trim($this->instance_token) !== '',
            'has_credentials' => $this->hasCredentials(),
            'is_active' => (bool) $this->is_active,
            'cart_recovery_enabled' => (bool) $this->cart_recovery_enabled,
            'pix_recovery_enabled' => (bool) $this->pix_recovery_enabled,
            'send_product_image' => (bool) $this->send_product_image,
            'cart_recovery_steps' => UazapiCartRecoverySteps::toUiSteps($this),
            'pix_recovery_steps' => UazapiCartRecoverySteps::toUiPixSteps($this),
            'message_pix' => $this->pixMessageTemplate(),
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'connected' => $this->isConnected(),
            'has_instance' => $this->hasCredentials(),
        ];
    }
}
