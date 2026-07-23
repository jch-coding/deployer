<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\Jobs\ExportMacAddressesToCentralJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'central-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = $this->client->deployments()->create(['name' => 'MAC Export']);
});

it('builds Central MAC registration CSV with empty client name and multi-tag quoting', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
        'status' => 'IN_PROGRESS',
        'central_static_tags' => ['BVSD-FACILITIES', 'BVSD-PUBLIC'],
    ]);

    $job = new ExportMacAddressesToCentralJob(
        [],
        $task,
        new CentralAPIHelper($this->client),
    );

    $csv = $job->buildMacRegistrationCsv([
        [
            'id' => 1,
            'mac_address' => 'F4-9A-B1-C0-A5-86',
            'serial' => 'SN1',
        ],
        [
            'id' => 2,
            'mac_address' => 'EC-1B-5F-CB-E6-6D',
            'serial' => 'SN2',
        ],
    ], ['BVSD-FACILITIES', 'BVSD-PUBLIC']);

    $lines = str_getcsv_lines($csv);

    expect($lines[0])->toBe(['MAC Address', 'Client Name', 'Enabled', 'Static Tags'])
        ->and($lines[1])->toBe(['F4-9A-B1-C0-A5-86', '', 'true', 'BVSD-FACILITIES, BVSD-PUBLIC'])
        ->and($lines[2])->toBe(['EC-1B-5F-CB-E6-6D', '', 'true', 'BVSD-FACILITIES, BVSD-PUBLIC']);

    expect($csv)->toContain('"BVSD-FACILITIES, BVSD-PUBLIC"');
});

it('exports MAC addresses to Central and marks devices completed', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'central_static_tags' => ['BVSD-AP'],
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'MACSN001',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        'https://us1.api.central.arubanetworks.com/network-config/v1alpha1/cnac-mac-reg/import' => Http::response([
            'jobid' => ['job-123'],
        ], 200),
    ]);

    $helper = new CentralAPIHelper($this->client);
    $job = new ExportMacAddressesToCentralJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->exportMacAddresses();

    expect($task->fresh()->status)->toBe('COMPLETED');

    $pivot = $task->devices()->where('devices.id', $device->id)->first()->pivot;
    expect($pivot->status)->toBe('COMPLETED');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }
        if (! str_contains($request->url(), 'cnac-mac-reg/import')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'AA-BB-CC-DD-EE-01')
            && str_contains($body, 'BVSD-AP')
            && str_contains($body, 'true');
    });
});

it('marks devices failed when Central import fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'MACSN002',
        'mac_address' => 'aa:bb:cc:dd:ee:02',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        'https://us1.api.central.arubanetworks.com/network-config/v1alpha1/cnac-mac-reg/import' => Http::response([
            'message' => 'Bad CSV',
        ], 400),
    ]);

    $helper = new CentralAPIHelper($this->client);
    $job = new ExportMacAddressesToCentralJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->exportMacAddresses();

    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED')
        ->and($task->fresh()->status)->toBe('FAILED');
});

/**
 * @return list<list<string|null>>
 */
function str_getcsv_lines(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}
