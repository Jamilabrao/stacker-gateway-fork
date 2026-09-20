<?php

namespace App\Http\Controllers;

use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use App\Services\Uazapi\UazapiCampaignAudience;
use App\Services\Uazapi\UazapiRecoveryInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UazapiRecoveryReportController extends Controller
{
    public function index(Request $request, UazapiRecoveryInsights $insights, UazapiCampaignAudience $audiences): Response
    {
        $period = $request->query('period', '7dias');
        if (! in_array($period, ['7dias', '30dias'], true)) {
            $period = '7dias';
        }

        $tenantId = (int) auth()->user()->tenant_id;
        $days = $period === '30dias' ? 30 : 7;
        $instance = UazapiInstance::forTenant($tenantId);

        $campaigns = UazapiCampaign::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (UazapiCampaign $campaign) => $campaign->toPublicArray())
            ->values()
            ->all();

        return Inertia::render('Relatorios/Whatsapp', [
            'period' => $period,
            'instance' => $instance?->toPublicArray(false),
            'metrics' => $insights->forTenant($tenantId, $days),
            'recent' => $insights->recentDispatches($tenantId),
            'audience_counts' => $audiences->counts($tenantId),
            'campaigns' => $campaigns,
            'campaign_defaults' => config('uazapi.campaign.defaults', []),
        ]);
    }
}
