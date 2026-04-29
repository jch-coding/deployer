<?php

namespace App;

final class JobQueueShard
{
    public const MAX_SHARD_COUNT = 8;

    public static function shardCount(): int
    {
        $configured = (int) config('task_job_queues.shard_count', 4);

        return max(1, min(self::MAX_SHARD_COUNT, $configured));
    }

    /**
     * @return list<string>
     */
    public static function allNames(): array
    {
        $n = self::shardCount();

        return array_map(static fn (int $i): string => 'q'.$i, range(0, $n - 1));
    }

    public static function commaSeparatedList(): string
    {
        return implode(',', self::allNames());
    }

    public static function isValid(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        if (preg_match('/^q(\d+)$/', $name, $matches) !== 1) {
            return false;
        }

        $index = (int) $matches[1];

        return $index >= 0 && $index < self::shardCount();
    }

    /**
     * Resolve a stored queue name for dispatch/clear; invalid or legacy values fall back to q0.
     */
    public static function resolve(?string $name): string
    {
        if (self::isValid($name)) {
            return $name;
        }

        return 'q0';
    }

    public static function fromUserEntropy(int $userId, string $entropy): string
    {
        $crc = crc32($userId.'|'.$entropy) & 0x7FFFFFFF;
        $index = $crc % self::shardCount();

        return 'q'.$index;
    }
}
