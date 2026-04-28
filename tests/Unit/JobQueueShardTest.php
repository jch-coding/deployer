<?php

use App\JobQueueShard;

beforeEach(function () {
    config(['task_job_queues.shard_count' => 8]);
});

test('fromUserEntropy is stable for same inputs', function () {
    expect(JobQueueShard::fromUserEntropy(42, 'fixed-entropy'))
        ->toBe(JobQueueShard::fromUserEntropy(42, 'fixed-entropy'));
});

test('allNames length matches shard count', function () {
    expect(JobQueueShard::allNames())->toHaveCount(8)
        ->and(JobQueueShard::allNames()[0])->toBe('q0')
        ->and(JobQueueShard::allNames()[7])->toBe('q7');
});

test('resolve maps legacy names to q0', function () {
    expect(JobQueueShard::resolve('default'))->toBe('q0')
        ->and(JobQueueShard::resolve('not-a-shard'))->toBe('q0');
});

test('isValid accepts q0 through q7 when count is 8', function () {
    expect(JobQueueShard::isValid('q0'))->toBeTrue()
        ->and(JobQueueShard::isValid('q7'))->toBeTrue()
        ->and(JobQueueShard::isValid('q8'))->toBeFalse();
});
