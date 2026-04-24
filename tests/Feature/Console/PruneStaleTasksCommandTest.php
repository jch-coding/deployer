<?php

use App\Models\Task;
use Illuminate\Support\Facades\Artisan;

it('removes tasks not updated in the last month and keeps recent tasks', function () {
    $stale = Task::factory()->create();
    $stale->timestamps = false;
    $stale->update(['updated_at' => now()->subMonths(2)]);

    $recent = Task::factory()->create();

    Artisan::call('tasks:prune-stale');

    expect(Task::query()->whereKey($stale->id)->exists())->toBeFalse();
    expect(Task::query()->whereKey($recent->id)->exists())->toBeTrue();
});
