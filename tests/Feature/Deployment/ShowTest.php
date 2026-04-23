<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use App\TaskType;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
});

it('shows a list of devices associated with the deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $devices = Device::factory(2)->for($deployment)->create();
    $this->actingAs($this->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertSeeHtml($devices->first()->name)
        ->assertSeeHtml($devices->last()->name);
});

it('filters devices by name, serial, or device function search', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    Device::factory()->for($deployment)->create([
        'name' => 'Alpha Switch',
        'serial' => 'SERIAL-ALPHA-100',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);
    Device::factory()->for($deployment)->create([
        'name' => 'Beta Switch',
        'serial' => 'SERIAL-BETA-200',
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ]);
    $this->actingAs($this->user);

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('Alpha'))
        ->assertOk()
        ->assertSeeHtml('Alpha Switch')
        ->assertDontSee('Beta Switch');

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('SERIAL-BETA'))
        ->assertOk()
        ->assertSeeHtml('Beta Switch')
        ->assertDontSee('Alpha Switch');

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('access_switch'))
        ->assertOk()
        ->assertSeeHtml('Beta Switch')
        ->assertDontSee('Alpha Switch');
});

it('has an upload devices button', function () {
   $this->actingAs($this->user);
   $deployment = Deployment::factory()->for($this->client)->create();
   visit(route('deployments.show', $deployment))
       ->assertSee('Add Devices')
       ->assertSee('No devices assigned to this deployment');
});

it('has a set of task types that are available for deployment', function ($task_name) {
    $this->withoutExceptionHandling();
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);

    visit(route('deployments.show', $deployment))
        ->assertSee($task_name);
})->with(array_map(fn($t) => $t->name, TaskType::cases()));

it('can add devices to a deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,site,description' . PHP_EOL . 'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device' . PHP_EOL . 'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2' . PHP_EOL
        );
    visit(route('deployments.show', $deployment))
        ->click('@add-devices')
        ->attach('input[name="devices"]', $uploadedFile->getPathname())
        ->press('@upload-devices')
        ->assertSee('Test Device 1')
        ->assertSee('Test Device 2');
});

