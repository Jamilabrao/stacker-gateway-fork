<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use Inertia\Inertia;
use Inertia\Response;

class UazapiController extends Controller
{
    public function index(): Response
    {
        $instances = UazapiInstance::query()
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (UazapiInstance $instance) => [
                'tenant_id' => $instance->tenant_id,
                'status' => $instance->status,
                'phone' => $instance->phone,
                'profile_name' => $instance->profile_name,
                'is_active' => $instance->is_active,
                'cart_recovery_enabled' => $instance->cart_recovery_enabled,
                'pix_recovery_enabled' => $instance->pix_recovery_enabled,
                'connected_at' => $instance->connected_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $recentDispatches = UazapiMessageDispatch::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (UazapiMessageDispatch $d) => [
                'id' => $d->id,
                'tenant_id' => $d->tenant_id,
                'event_type' => $d->event_type,
                'sequence_step' => $d->sequence_step,
                'phone' => $d->phone,
                'status' => $d->status,
                'wa_status' => $d->wa_status,
                'error' => $d->error,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Platform/Uazapi/Index', [
            'docs_url' => (string) config('uazapi.docs_url'),
            'instances' => $instances,
            'recent_dispatches' => $recentDispatches,
        ]);
    }
}
