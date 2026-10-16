<?php

use App\Actions\Provisioning\ExportMacToCentralNacAction;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;

it('fails when mac address is missing', function () {
    $device = Device::factory()->make([
        'serial' => 'SN1',
        'mac_address' => null,
    ]);
    $helper = mock(CentralAPIHelper::class);

    $result = (new ExportMacToCentralNacAction)->execute($device, $helper, ['campus']);

    expect($result->isFailed())->toBeTrue()
        ->and($result->message)->toContain('mac_address');
});

it('exports a mac with static tags', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $device = Device::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'serial' => 'SN-EXPORT-1',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
    ]);

    $helper = mock(CentralAPIHelper::class);
    $helper->shouldReceive('importMacCsvFile')
        ->once()
        ->with(\Mockery::on(function (string $csv): bool {
            return str_contains($csv, 'AA-BB-CC-DD-EE-FF')
                && str_contains($csv, 'campus, lab');
        }))
        ->andReturn(['success' => true, 'job_ids' => ['job-1']]);

    $result = (new ExportMacToCentralNacAction)->execute($device, $helper, ['campus', 'lab']);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->message)->toContain('AA-BB-CC-DD-EE-FF')
        ->and($result->message)->toContain('job-1');
});
