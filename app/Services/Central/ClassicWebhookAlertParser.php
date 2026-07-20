<?php

namespace App\Services\Central;

class ClassicWebhookAlertParser
{
    private const WAKE_ALERT_TYPES = [
        'new ap detected',
        'new switch connected',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isOnlineWakeAlert(array $payload): bool
    {
        $alertType = $this->normalizeAlertType((string) ($payload['alert_type'] ?? ''));

        return in_array($alertType, self::WAKE_ALERT_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractSerial(array $payload): ?string
    {
        $details = $payload['details'] ?? null;
        if (is_array($details)) {
            $fromDetails = trim((string) ($details['serial'] ?? ''));
            if ($fromDetails !== '') {
                return $fromDetails;
            }
        }

        $fromDeviceId = trim((string) ($payload['device_id'] ?? ''));

        return $fromDeviceId !== '' ? $fromDeviceId : null;
    }

    private function normalizeAlertType(string $alertType): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($alertType)) ?? '');
    }
}
