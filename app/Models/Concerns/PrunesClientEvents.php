<?php

namespace App\Models\Concerns;

trait PrunesClientEvents
{
    public static function pruneForClient(int $clientId, int $keep = 500): void
    {
        $idsToKeep = static::query()
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('id');

        static::query()
            ->where('client_id', $clientId)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }
}
