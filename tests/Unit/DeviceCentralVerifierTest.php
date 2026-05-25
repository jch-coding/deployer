<?php

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use App\Services\DeviceCentralVerifier;

function deviceCentralVerifierFixtures(string $taskType = 'ASSOCIATE_DEVICE_TO_SITE'): array
{
    $client = Client::factory()->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->for($client)->create([
        'name' => 'Site A',
        'scope_id' => 'site-scope-123',
    ]);
    $device = Device::factory()->for($deployment)->for($site)->create([
        'name' => 'Switch-A',
        'serial' => 'SN12345',
    ]);
    $task = Task::factory()->for($deployment)->create([
        'task_type' => $taskType,
        'status' => 'COMPLETED',
    ]);
    $task->devices()->attach($device->id, ['status' => 'COMPLETED']);

    return compact('client', 'deployment', 'site', 'device', 'task');
}

test('device central verifier passes when central device matches site association', function () {
    $fixtures = deviceCentralVerifierFixtures('ASSOCIATE_DEVICE_TO_SITE');

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')
        ->once()
        ->with(['filter' => 'siteId eq site-scope-123'])
        ->andReturn([
            [
                'serialNumber' => 'SN12345',
                'siteId' => 'site-scope-123',
                'deviceName' => 'Other-Name',
            ],
        ]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['ok'])->toBeTrue()
        ->and($result['device_errors'])->toBe([]);
});

test('device central verifier passes when central device matches site and name', function () {
    $fixtures = deviceCentralVerifierFixtures('ASSOCIATE_SITE_AND_NAME');

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')
        ->once()
        ->andReturn([
            [
                'serialNumber' => 'SN12345',
                'siteId' => 'site-scope-123',
                'deviceName' => 'Switch-A',
            ],
        ]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'][0]['ok'])->toBeTrue();
});

test('device central verifier passes naming task when device name matches', function () {
    $fixtures = deviceCentralVerifierFixtures('UPDATE_SYSTEM_INFO');

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')
        ->once()
        ->andReturn([
            [
                'serialNumber' => 'SN12345',
                'siteId' => 'other-site',
                'deviceName' => 'Switch-A',
            ],
        ]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'][0]['ok'])->toBeTrue();
});

test('device central verifier fails when device missing in central', function () {
    $fixtures = deviceCentralVerifierFixtures();

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')->once()->andReturn([]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'][0]['ok'])->toBeFalse()
        ->and($result['results'][0]['missing_in_central'])->toBeTrue();
});

test('device central verifier fails on site id mismatch', function () {
    $fixtures = deviceCentralVerifierFixtures('ASSOCIATE_DEVICE_TO_SITE');

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')->once()->andReturn([
        [
            'serialNumber' => 'SN12345',
            'siteId' => 'wrong-site',
        ],
    ]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'][0]['ok'])->toBeFalse()
        ->and($result['results'][0]['diff'][0]['path'])->toBe('siteId');
});

test('device central verifier fails on device name mismatch for site and name task', function () {
    $fixtures = deviceCentralVerifierFixtures('ASSOCIATE_SITE_AND_NAME');

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')->once()->andReturn([
        [
            'serialNumber' => 'SN12345',
            'siteId' => 'site-scope-123',
            'deviceName' => 'Wrong-Name',
        ],
    ]);

    $result = (new DeviceCentralVerifier)->verify($fixtures['task'], $helper);

    expect($result['results'][0]['ok'])->toBeFalse()
        ->and($result['results'][0]['diff'][0]['path'])->toBe('deviceName');
});

test('device central verifier reports error when site scope id is missing', function () {
    $fixtures = deviceCentralVerifierFixtures();
    $fixtures['site']->update(['scope_id' => null]);

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')->never();

    $result = (new DeviceCentralVerifier)->verify($fixtures['task']->fresh(), $helper);

    expect($result['device_errors'])->toHaveCount(1)
        ->and($result['results'][0]['ok'])->toBeFalse();
});

test('device central verifier groups devices by site for a single central fetch', function () {
    $client = Client::factory()->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->for($client)->create(['scope_id' => 'site-scope-123']);
    $deviceA = Device::factory()->for($deployment)->for($site)->create(['serial' => 'SN-A', 'name' => 'A']);
    $deviceB = Device::factory()->for($deployment)->for($site)->create(['serial' => 'SN-B', 'name' => 'B']);
    $task = Task::factory()->for($deployment)->create(['task_type' => 'ASSOCIATE_DEVICE_TO_SITE']);
    $task->devices()->attach([
        $deviceA->id => ['status' => 'COMPLETED'],
        $deviceB->id => ['status' => 'COMPLETED'],
    ]);

    $helper = mock(CentralAPIHelper::class)->makePartial();
    $helper->shouldReceive('get_all_devices')->once()->andReturn([
        ['serialNumber' => 'SN-A', 'siteId' => 'site-scope-123'],
        ['serialNumber' => 'SN-B', 'siteId' => 'site-scope-123'],
    ]);

    $result = (new DeviceCentralVerifier)->verify($task, $helper);

    expect(collect($result['results'])->where('ok', true))->toHaveCount(2);
});

test('buildExpectedFields includes fields based on task type', function () {
    $verifier = new DeviceCentralVerifier;
    $site = Site::factory()->make(['scope_id' => 'scope-1']);
    $device = Device::factory()->make(['name' => 'Dev', 'serial' => 'SER']);

    expect($verifier->buildExpectedFields($device, $site, 'ASSOCIATE_DEVICE_TO_SITE'))
        ->toBe(['serialNumber' => 'SER', 'siteId' => 'scope-1']);

    expect($verifier->buildExpectedFields($device, $site, 'ASSOCIATE_SITE_AND_NAME'))
        ->toBe(['serialNumber' => 'SER', 'siteId' => 'scope-1', 'deviceName' => 'Dev']);

    expect($verifier->buildExpectedFields($device, $site, 'UPDATE_SYSTEM_INFO'))
        ->toBe(['serialNumber' => 'SER', 'deviceName' => 'Dev']);
});
