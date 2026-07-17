<?php

use App\Helper\CentralAPIHelper;
use App\Http\Controllers\TaskController;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;

test('get_unique_sw_profiles returns a collection of unique port profiles', function () {
   $devices = App\Models\Device::factory(2)->create();
   $dev1_ints = collect([
       [
           'interface' => '1/1/1',
           'sw_profile' => 'profile1'
       ],
       [
           'interface' => '1/1/2',
           'sw_profile' => 'profile1',
       ]
   ]);
   $dev2_ints = collect([
       [
           'interface' => '1/1/1',
           'sw_profile' => 'profile2'
       ],
       [
           'interface' => '1/1/2',
           'sw_profile' => 'profile3',
       ]
   ]);
   $dev1 = $devices->first();
   $dev2 = $devices->last();
   $dev1->interfaces()->createMany($dev1_ints->toArray());
   $dev2->interfaces()->createMany($dev2_ints->toArray());
   $devices->each(function ($device) {
       $device->setRelation('interfaces_sw_profiles', $device->interfaces()->whereNotNull('sw_profile')->get());
   });
   $expected = collect([
       $dev1->interfaces->first(),
       $dev2->interfaces->first(),
       $dev2->interfaces->last(),
   ]);
   $actual = TaskController::get_unique_sw_profiles($devices);
   expect($actual)->toEqual($expected);
});

test('chunk_devices returns an array with the groupBy key as an array of keys and the chunked devices as arrays', function () {
    $task_controller = new TaskController();
   $group1_devices = array_map(fn($n) => ['name' => 'dev'.$n, 'group' => 'group1'], range(1, 100));
   $group2_devices = array_map(fn($n) => ['name' => 'dev'.$n, 'group' => 'group2'], range(1, 55));
   $devices_by_group = collect(array_merge($group1_devices, $group2_devices))->groupBy('group');
   $actual = $task_controller->chunk_devices($devices_by_group);
   expect($actual)->toBeArray();
   expect($actual)->toHaveKeys(['keys', 'chunked_devices_by_group']);
   $chunked_devices = $actual['chunked_devices_by_group'];
   expect(count($chunked_devices))->toBe(2);
   expect($chunked_devices['group1'])->toHaveCount(4);
   expect($chunked_devices['group2'])->toHaveCount(3);
});

test('create_jobs_by_grouped_chunks creates a flat array of jobs from the grouped chunks', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $user->clients->first()->update(['current' => true]);
    $helper = new CentralAPIHelper($client->refresh());
    $task_controller = new TaskController();
    $group1_devices = array_map(fn($n) => ['name' => 'dev'.$n, 'group' => 'group1'], range(1, 100));
    $group2_devices = array_map(fn($n) => ['name' => 'dev'.$n, 'group' => 'group2'], range(1, 55));
    $devices_by_group = collect(array_merge($group1_devices, $group2_devices))->groupBy('group');
    $chunked_devices = $task_controller->chunk_devices($devices_by_group);
    $actual = $task_controller->create_jobs_by_grouped_chunks($chunked_devices, $task, $helper, MoveDevicesToGroupJob::class);
    expect($actual)->toBeArray();
    expect($actual)->tohaveCount(7);
    expect($actual)->each->toBeInstanceOf(MoveDevicesToGroupJob::class);
});

test('create_jobs_by_grouped_chunks returns an empty array on empty input', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $user->clients->first()->update(['current' => true]);
    $helper = new CentralAPIHelper($client->refresh());
    $task_controller = new TaskController();

    $empty = [
        'keys' => [],
        'chunked_devices_by_group' => [],
    ];

    $actual = $task_controller->create_jobs_by_grouped_chunks($empty, $task, $helper, MoveDevicesToGroupJob::class);
    expect($actual)->toBeArray()->toBeEmpty();
});

test('create_jobs_by_grouped_chunks keeps key to chunk alignment and job payload integrity', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $user->clients->first()->update(['current' => true]);
    $helper = new CentralAPIHelper($client->refresh());
    $task_controller = new TaskController();

    $payload = [
        'keys' => ['alpha-group', 'beta-group'],
        'chunked_devices_by_group' => [
            [
                [
                    ['id' => 1, 'serial' => 'CN111', 'name' => 'device-1'],
                    ['id' => 2, 'serial' => 'CN222', 'name' => 'device-2'],
                ],
            ],
            [
                [
                    ['id' => 3, 'serial' => 'CN333', 'name' => 'device-3'],
                ],
                [
                    ['id' => 4, 'serial' => 'CN444', 'name' => 'device-4'],
                ],
            ],
        ],
    ];

    $actual = $task_controller->create_jobs_by_grouped_chunks($payload, $task, $helper, MoveDevicesToGroupJob::class);

    expect($actual)->toHaveCount(3)
        ->and($actual[0])->toBeInstanceOf(MoveDevicesToGroupJob::class)
        ->and($actual[0]->group_name)->toBe('alpha-group')
        ->and($actual[0]->devices)->toHaveCount(2)
        ->and($actual[0]->devices[0]['serial'])->toBe('CN111')
        ->and($actual[0]->task->is($task))->toBeTrue()
        ->and($actual[0]->centralAPIHelper)->toBe($helper)
        ->and($actual[1]->group_name)->toBe('beta-group')
        ->and($actual[1]->devices)->toHaveCount(1)
        ->and($actual[1]->devices[0]['serial'])->toBe('CN333')
        ->and($actual[2]->group_name)->toBe('beta-group')
        ->and($actual[2]->devices)->toHaveCount(1)
        ->and($actual[2]->devices[0]['serial'])->toBe('CN444');
});

test('create_jobs_by_grouped_chunks supports assign device function jobs', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $user->clients->first()->update(['current' => true]);
    $helper = new CentralAPIHelper($client->refresh());
    $task_controller = new TaskController();

    $payload = [
        'keys' => ['ACCESS'],
        'chunked_devices_by_group' => [
            [
                [
                    ['id' => 10, 'serial' => 'CNACCESS1', 'name' => 'switch-a'],
                ],
            ],
        ],
    ];

    $actual = $task_controller->create_jobs_by_grouped_chunks($payload, $task, $helper, AssignDeviceFunctionJob::class);

    expect($actual)->toHaveCount(1)
        ->and($actual[0])->toBeInstanceOf(AssignDeviceFunctionJob::class)
        ->and($actual[0]->device_function)->toBe('ACCESS')
        ->and($actual[0]->devices[0]['serial'])->toBe('CNACCESS1');
});
