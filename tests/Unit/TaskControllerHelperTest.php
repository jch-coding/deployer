<?php

use App\Helper\CentralAPIHelper;
use App\Http\Controllers\TaskController;
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
    $actual = $task_controller->create_jobs_by_grouped_chunks($chunked_devices, $task, $helper, \App\Jobs\PreprovisionDevicesToGroupJob::class);
    expect($actual)->toBeArray();
    expect($actual)->tohaveCount(7);

});
