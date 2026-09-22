<?php

namespace App\Services\Whatsapp;

use App\Models\EvolutionMessageDispatch;
use App\Models\EvolutionOptOut;
use App\Models\EvolutionRecoveryStop;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use DateTimeInterface;

class WhatsappRecoveryGuard
{
    public static function isOptedOut(int $tenantId, string $phone): bool
    {
        return UazapiOptOut::isOptedOut($tenantId, $phone)
            || EvolutionOptOut::isOptedOut($tenantId, $phone);
    }

    public static function blocks(int $tenantId, string $phone, DateTimeInterface $startedAt): bool
    {
        return UazapiRecoveryStop::blocks($tenantId, $phone, $startedAt)
            || EvolutionRecoveryStop::blocks($tenantId, $phone, $startedAt);
    }

    public static function sessionTaken(int $sessionId, string $eventType, int $stepIndex): bool
    {
        return UazapiMessageDispatch::hasPendingStepForSession($sessionId, $stepIndex, $eventType)
            || EvolutionMessageDispatch::hasPendingStepForSession($sessionId, $stepIndex, $eventType)
            || in_array($stepIndex, UazapiMessageDispatch::consumedStepIndicesForSession($sessionId, $eventType), true)
            || in_array($stepIndex, EvolutionMessageDispatch::consumedStepIndicesForSession($sessionId, $eventType), true);
    }

    public static function orderStepTaken(int $orderId, string $eventType, int $stepIndex): bool
    {
        return UazapiMessageDispatch::alreadyQueuedForOrderStep($orderId, $eventType, $stepIndex)
            || EvolutionMessageDispatch::alreadyQueuedForOrderStep($orderId, $eventType, $stepIndex);
    }
}
