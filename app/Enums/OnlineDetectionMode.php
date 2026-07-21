<?php

namespace App\Enums;

enum OnlineDetectionMode: string
{
    case Poll = 'poll';
    case Webhook = 'webhook';
    case Stream = 'stream';

    public function label(): string
    {
        return match ($this) {
            self::Poll => 'Poll Central',
            self::Webhook => 'Webhook',
            self::Stream => 'Streaming API',
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

    public function usesStreaming(): bool
    {
        return $this === self::Stream;
    }

    public function waitsForExternalWake(): bool
    {
        return $this === self::Webhook || $this === self::Stream;
    }
}
