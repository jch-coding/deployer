<?php

namespace App\Enums;

enum OnlineDetectionMode: string
{
    case Poll = 'poll';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Poll => 'Poll Central',
            self::Webhook => 'Webhook',
        };
    }

    public function usesPoller(): bool
    {
        return $this === self::Poll;
    }

    public function usesWebhook(): bool
    {
        return $this === self::Webhook;
    }
}
