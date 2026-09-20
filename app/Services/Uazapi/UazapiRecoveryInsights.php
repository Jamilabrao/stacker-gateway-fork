<?php

namespace App\Services\Uazapi;

use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use Carbon\CarbonInterface;

class UazapiRecoveryInsights
{
    /**
     * @return array<string, mixed>
     */
    public function forTenant(int $tenantId, int $days = 7): array
    {
        $start = now()->subDays(max(1, $days))->startOfDay();
        $sentQuery = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $start);

        $sent = (clone $sentQuery)->count();
        $cartSent = (clone $sentQuery)->where('event_type', UazapiInstance::EVENT_CART_RECOVERY)->count();
        $pixSent = (clone $sentQuery)->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)->count();

        $delivered = (clone $sentQuery)
            ->whereIn('wa_status', ['Delivered', 'Read', 'Played'])
            ->count();
        $read = (clone $sentQuery)
            ->whereIn('wa_status', ['Read', 'Played'])
            ->count();

        $failed = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_FAILED)
            ->where('created_at', '>=', $start)
            ->count();

        $canceled = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_CANCELED)
            ->where('created_at', '>=', $start)
            ->count();

        $replied = UazapiRecoveryStop::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->count();

        $optOuts = UazapiOptOut::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->count();

        $cartConverted = $this->convertedCart($tenantId, $start);
        $pixConverted = $this->convertedPix($tenantId, $start);
        $convertedIds = array_values(array_unique(array_merge(
            $cartConverted['order_ids'],
            $pixConverted['order_ids']
        )));
        $convertedAmount = (float) Order::query()
            ->whereIn('id', $convertedIds !== [] ? $convertedIds : [0])
            ->sum('amount');

        return [
            'days' => $days,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'replied' => $replied,
            'converted' => count($convertedIds),
            'converted_amount' => round($convertedAmount, 2),
            'opt_outs' => $optOuts,
            'failed' => $failed,
            'canceled' => $canceled,
            'cart_sent' => $cartSent,
            'pix_sent' => $pixSent,
            'cart_converted' => count($cartConverted['order_ids']),
            'pix_converted' => count($pixConverted['order_ids']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentDispatches(int $tenantId, int $limit = 30): array
    {
        return UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UazapiMessageDispatch $dispatch) => [
                'id' => $dispatch->id,
                'event_type' => $dispatch->event_type,
                'status' => $dispatch->status,
                'wa_status' => $dispatch->wa_status,
                'phone' => $dispatch->phone,
                'sequence_step' => $dispatch->sequence_step,
                'error' => $dispatch->error,
                'sent_at' => $dispatch->sent_at?->toIso8601String(),
                'created_at' => $dispatch->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{order_ids: list<int>}
     */
    private function convertedCart(int $tenantId, CarbonInterface $start): array
    {
        $sessionIds = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', UazapiInstance::EVENT_CART_RECOVERY)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $start)
            ->whereNotNull('checkout_session_id')
            ->pluck('checkout_session_id')
            ->unique()
            ->all();

        if ($sessionIds === []) {
            return ['order_ids' => []];
        }

        $orderIds = \App\Models\CheckoutSession::query()
            ->whereIn('id', $sessionIds)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique()
            ->all();

        return ['order_ids' => $this->completedOrderIds($orderIds)];
    }

    /**
     * @return array{order_ids: list<int>}
     */
    private function convertedPix(int $tenantId, CarbonInterface $start): array
    {
        $orderIds = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $start)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique()
            ->all();

        return ['order_ids' => $this->completedOrderIds($orderIds)];
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return list<int>
     */
    private function completedOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return Order::query()
            ->whereIn('id', $orderIds)
            ->where('status', 'completed')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
