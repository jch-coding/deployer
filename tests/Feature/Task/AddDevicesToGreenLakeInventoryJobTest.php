<?php

use App\Helper\GreenLakeAPIHelper;
use App\Jobs\AddDevicesToGreenLakeInventoryJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = $this->client->deployments()->create(['name' => 'GreenLake Add Devices']);
});

it('marks devices completed when GreenLake async add succeeds', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN001',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-ok',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-ok' => Http::response([
            'id' => 'async-ok',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN001']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    expect($task->fresh()->status)->toBe('COMPLETED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->status)->toBe('COMPLETED')
        ->and($pivot->greenlake_step_statuses)->toBe(['inventory' => 'COMPLETED'])
        ->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 1, 'total' => 1]);
});

it('marks devices failed when GreenLake async add fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN002',
        'mac_address' => 'aa:bb:cc:dd:ee:02',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-fail',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-fail' => Http::response([
            'id' => 'async-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failed' => [['serialNumber' => 'ADDSN002']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED')
        ->and($task->fresh()->status)->toBe('FAILED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->greenlake_step_statuses)->toBe(['inventory' => 'FAILED'])
        ->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 0, 'total' => 1]);
});

it('forwards task greenlake_tags into the GreenLake create payload', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => [
            'Environment' => 'prod',
            'Site' => '',
        ],
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-TAGS',
        'mac_address' => 'aa:bb:cc:dd:ee:03',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices*' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['transactionId' => 'async-tags'], 202, [
                    'Location' => '/devices/v1/async-operations/async-tags',
                ]);
            }

            return Http::response([
                'items' => [[
                    'id' => 'gl-dev-tags',
                    'serialNumber' => 'ADDSN-TAGS',
                    'macAddress' => 'aa:bb:cc:dd:ee:03',
                ]],
                'total' => 1,
            ], 200);
        },
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-tags' => Http::response([
            'id' => 'async-tags',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN-TAGS']],
            ],
        ], 200),
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-patch-tags',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-patch-tags',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-patch-tags' => Http::response([
            'id' => 'async-patch-tags',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-dev-tags']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/devices/v1/devices')) {
            return false;
        }

        $body = $request->data();
        $network = $body['network'][0] ?? [];

        return ($network['serialNumber'] ?? null) === 'ADDSN-TAGS'
            && ($network['tags']['Environment'] ?? null) === 'prod'
            && array_key_exists('Site', $network['tags'] ?? [])
            && ($network['tags']['Site'] ?? null) === '';
    });

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/devices/v2beta1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['tags']['Environment'] ?? null) === 'prod'
            && array_key_exists('Site', $body['tags'] ?? [])
            && ($body['tags']['Site'] ?? null) === ''
            && str_contains($request->url(), 'id=gl-dev-tags');
    });

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->greenlake_step_statuses)->toBe([
        'inventory' => 'COMPLETED',
        'tags' => 'COMPLETED',
    ])->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 2, 'total' => 2]);
});

it('assigns greenlake location after a successful inventory add', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_location_id' => 'loc-nyc',
        'greenlake_location_name' => 'Warehouse NYC',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-LOC',
        'mac_address' => 'aa:bb:cc:dd:ee:04',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices*' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['transactionId' => 'async-add-loc'], 202, [
                    'Location' => '/devices/v1/async-operations/async-add-loc',
                ]);
            }

            return Http::response([
                'items' => [[
                    'id' => 'gl-dev-loc',
                    'serialNumber' => 'ADDSN-LOC',
                    'macAddress' => 'aa:bb:cc:dd:ee:04',
                ]],
                'total' => 1,
            ], 200);
        },
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-add-loc' => Http::response([
            'id' => 'async-add-loc',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN-LOC']],
            ],
        ], 200),
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-assign-loc',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-assign-loc',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-assign-loc' => Http::response([
            'id' => 'async-assign-loc',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-dev-loc']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/devices/v2beta1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['location']['id'] ?? null) === 'loc-nyc';
    });

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->greenlake_step_statuses)->toBe([
        'inventory' => 'COMPLETED',
        'location' => 'COMPLETED',
    ])->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 2, 'total' => 2]);
});

it('does not patch location when task has no greenlake_location_id', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_location_id' => null,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-NOLOC',
        'mac_address' => 'aa:bb:cc:dd:ee:05',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-noloc',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-noloc' => Http::response([
            'id' => 'async-noloc',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN-NOLOC']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/devices/v2beta1/devices'));

    expect($task->fresh()->status)->toBe('COMPLETED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->greenlake_step_statuses)->toBe(['inventory' => 'COMPLETED']);
});

it('tracks inventory tags and location steps and totals after full success', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => ['Environment' => 'prod'],
        'greenlake_location_id' => 'loc-nyc',
        'greenlake_location_name' => 'Warehouse NYC',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-ALL',
        'mac_address' => 'aa:bb:cc:dd:ee:06',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices*' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['transactionId' => 'async-all'], 202, [
                    'Location' => '/devices/v1/async-operations/async-all',
                ]);
            }

            return Http::response([
                'items' => [[
                    'id' => 'gl-dev-all',
                    'serialNumber' => 'ADDSN-ALL',
                    'macAddress' => 'aa:bb:cc:dd:ee:06',
                ]],
                'total' => 1,
            ], 200);
        },
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-all' => Http::response([
            'id' => 'async-all',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN-ALL']],
            ],
        ], 200),
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::sequence()
            ->push([
                'transactionId' => 'async-patch-tags-all',
            ], 202, [
                'Location' => '/devices/v1/async-operations/async-patch-tags-all',
            ])
            ->push([
                'transactionId' => 'async-assign-loc-all',
            ], 202, [
                'Location' => '/devices/v1/async-operations/async-assign-loc-all',
            ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-patch-tags-all' => Http::response([
            'id' => 'async-patch-tags-all',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-dev-all']],
            ],
        ], 200),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-assign-loc-all' => Http::response([
            'id' => 'async-assign-loc-all',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-dev-all']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($pivot->status)->toBe('COMPLETED')
        ->and($pivot->greenlake_step_statuses)->toBe([
            'inventory' => 'COMPLETED',
            'tags' => 'COMPLETED',
            'location' => 'COMPLETED',
        ])
        ->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 3, 'total' => 3])
        ->and($task->applicableGreenLakeSteps())->toBe(['inventory', 'tags', 'location']);
});

it('keeps inventory completed when tag update fails after add', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => ['Environment' => 'prod'],
        'greenlake_location_id' => 'loc-nyc',
        'greenlake_location_name' => 'Warehouse NYC',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-TAGFAIL',
        'mac_address' => 'aa:bb:cc:dd:ee:07',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices*' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['transactionId' => 'async-tagfail'], 202, [
                    'Location' => '/devices/v1/async-operations/async-tagfail',
                ]);
            }

            return Http::response([
                'items' => [[
                    'id' => 'gl-dev-tagfail',
                    'serialNumber' => 'ADDSN-TAGFAIL',
                    'macAddress' => 'aa:bb:cc:dd:ee:07',
                ]],
                'total' => 1,
            ], 200);
        },
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-tagfail' => Http::response([
            'id' => 'async-tagfail',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN-TAGFAIL']],
            ],
        ], 200),
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-patch-tags-fail',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-patch-tags-fail',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-patch-tags-fail' => Http::response([
            'id' => 'async-patch-tags-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failedDevices' => [['id' => 'gl-dev-tagfail']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->status)->toBe('FAILED')
        ->and($pivot->greenlake_step_statuses)->toBe([
            'inventory' => 'COMPLETED',
            'tags' => 'FAILED',
            'location' => 'FAILED',
        ])
        ->and($task->trackedItemTotals())->toBe(['category' => 'DEVICE', 'completed' => 1, 'total' => 3]);
});

it('does not flip already completed sibling devices when job fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => ['Environment' => 'prod'],
    ]);

    $completedDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-DONE',
        'mac_address' => 'aa:bb:cc:dd:ee:08',
    ]);
    $pendingDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN-PEND',
        'mac_address' => 'aa:bb:cc:dd:ee:09',
    ]);

    $task->devices()->attach($completedDevice->id, [
        'status' => 'COMPLETED',
        'greenlake_step_statuses' => [
            'inventory' => 'COMPLETED',
            'tags' => 'COMPLETED',
        ],
    ]);
    $task->devices()->attach($pendingDevice->id, [
        'status' => 'IN_PROGRESS',
        'greenlake_step_statuses' => [
            'inventory' => 'COMPLETED',
            'tags' => 'PENDING',
        ],
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $pendingDevice->id,
            'serial' => $pendingDevice->serial,
            'mac_address' => $pendingDevice->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->failed(new RuntimeException('chunk timed out'));

    $completedPivot = $task->devices()->where('devices.id', $completedDevice->id)->first()->pivot;
    $pendingPivot = $task->devices()->where('devices.id', $pendingDevice->id)->first()->pivot;

    expect($completedPivot->status)->toBe('COMPLETED')
        ->and($completedPivot->greenlake_step_statuses)->toBe([
            'inventory' => 'COMPLETED',
            'tags' => 'COMPLETED',
        ])
        ->and($pendingPivot->status)->toBe('FAILED')
        ->and($pendingPivot->greenlake_step_statuses)->toBe([
            'inventory' => 'COMPLETED',
            'tags' => 'FAILED',
        ])
        ->and($task->fresh()->status)->toBe('FAILED')
        ->and($task->trackedItemTotals()['completed'])->toBe(3);
});
