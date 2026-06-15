<?php

namespace App\Services\Provisioning;

class ProvisioningStepResult
{
    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_RETRY = 'retry';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_WAITING_PEER = 'waiting_peer';

    public const OUTCOME_DELEGATED = 'delegated';

    private function __construct(
        public readonly string $outcome,
        public readonly string $message = '',
    ) {}

    public static function completed(string $message = ''): self
    {
        return new self(self::OUTCOME_COMPLETED, $message);
    }

    public static function retry(string $message): self
    {
        return new self(self::OUTCOME_RETRY, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::OUTCOME_FAILED, $message);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(self::OUTCOME_SKIPPED, $message);
    }

    public static function waitingPeer(string $message): self
    {
        return new self(self::OUTCOME_WAITING_PEER, $message);
    }

    public static function delegated(string $message = ''): self
    {
        return new self(self::OUTCOME_DELEGATED, $message);
    }

    public function isCompleted(): bool
    {
        return $this->outcome === self::OUTCOME_COMPLETED;
    }

    public function isRetry(): bool
    {
        return $this->outcome === self::OUTCOME_RETRY;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    public function isWaitingPeer(): bool
    {
        return $this->outcome === self::OUTCOME_WAITING_PEER;
    }

    public function isDelegated(): bool
    {
        return $this->outcome === self::OUTCOME_DELEGATED;
    }
}
