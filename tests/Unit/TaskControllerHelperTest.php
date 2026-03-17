<?php

use App\Http\Controllers\TaskController;

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
