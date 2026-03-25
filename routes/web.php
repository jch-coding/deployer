<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::controller(ClientController::class)->group(function () {
        Route::get('/clients', 'index')->name('clients.index');
        Route::put('/clients/edit/{client}', 'update')->name('clients.edit');
        Route::put('/clients/current/{client}', 'updateCurrent')->name('clients.current');
        Route::post('/clients', 'store')->name('clients.store');
        Route::post('/clients/test_central_creds/{client}', 'testCentralCreds')->name('clients.test_central_creds');
        Route::delete('/clients/{client}', 'destroy')->name('clients.destroy');
    });

    Route::controller(DeploymentController::class)->group(function () {
        Route::get('/deployments', 'index')->name('deployments.index');
        Route::post('/deployments', 'store')->name('deployments.store');
        Route::delete('/deployments/{deployment}', 'destroy')->name('deployments.destroy');
        Route::get('/deployments/{deployment}', 'show')->name('deployments.show');
    });

    Route::controller(DeviceController::class)->group(function () {
        Route::post('devices/store-many/{deployment}', 'storeMany')->name('devices.store-many');
        Route::post('/devices/{deployment}', 'store')->name('devices.store');
        Route::put('/devices/edit/{device}', 'update')->name('devices.edit');
        Route::delete('/devices/{device}', 'destroy')->name('devices.destroy');
    });

    Route::controller(DispatchController::class)->group(function () {
        Route::get('/dispatcher/dispatch/{task}', 'dispatch')->name('dispatcher.dispatch');
    });

    Route::controller(TaskController::class)->group(function () {
        Route::get('/tasks/update_system_info/{task}', 'showSystemInfo')->name('tasks.show-system-info');
        Route::get('/tasks/ethernet_interface/{task}', 'showEthernetInterface')->name('tasks.show-ethernet-interface');
        Route::get('/tasks/lag_interface/{task}', 'showLagInterface')->name('tasks.show-lag-interface');
        Route::get('/tasks/vlan_interface/{task}', 'showVlanInterface')->name('tasks.show-vlan-interface');
        Route::get('/tasks/assign_device_function/{task}', 'showAssignDeviceFunction')->name('tasks.show-assign-device-function');
        Route::get('/tasks/preprovision_device_to_group/{task}', 'showPreprovisionDeviceToGroup')->name('tasks.show-preprovision-device-to-group');
        Route::get('/tasks/associate_site_and_name/{task}', 'showAssociateSiteAndName')->name('tasks.show-associate-site-and-name');
        Route::post('/tasks/deployment/{deployment}', 'store')->name('tasks.store');
        Route::post('/tasks/force_restart/{task}', 'force_restart')->name('tasks.force_restart');
        Route::post('/tasks/test', 'test')->name('tasks.test');
        Route::patch('/tasks/{task}', 'cancel')->name('tasks.cancel');
    });
});

require __DIR__.'/settings.php';
