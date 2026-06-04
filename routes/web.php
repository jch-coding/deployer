<?php

use App\Http\Controllers\CentralApiExplorerController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\LicensingController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TaskController;
use App\Http\Resources\ClientResource;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/documentation', function () {
    return Inertia::render('documentation');
})->name('documentation');

Route::get('/usage', function () {
    return Inertia::render('Usage');
})->name('usage');

Route::get('dashboard', function () {
    $clients = auth()->user()->clients()->withCount(['deployments', 'devices'])->get();

    return Inertia::render('dashboard', [
        'clients' => $clients->toResourceCollection(ClientResource::class),
    ]);
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
        Route::get('/deployments/{deployment}/critical-check', 'criticalCheck')->name('deployments.critical_check');
        Route::get('/deployments/{deployment}/critical-check/step/{step}', 'criticalCheckStep')->name('deployments.critical_check.step');
        Route::patch('/deployments/{deployment}/critical-check/failed-interfaces', 'patchCriticalCheckFailedInterfaces')->name('deployments.critical_check.failed_interfaces');
        Route::post('/deployments/{deployment}/relaunch-failed-critical-config', 'relaunchFailedCriticalConfig')->name('deployments.relaunch_failed_critical_config');
    });

    Route::controller(DeviceController::class)->group(function () {
        Route::post('/deployments/{deployment}/refresh-scope-ids', 'refreshScopeIds')->name('deployments.refresh-scope-ids');
        Route::post('devices/store-many/{deployment}', 'storeMany')->name('devices.store-many');
        Route::post('/devices/{deployment}', 'store')->name('devices.store');
        Route::get('/devices/{device}', 'show')->name('devices.show');
        Route::patch('/devices/{device}', 'updateMetadata')->name('devices.update-metadata');
        Route::patch('/devices/{device}/interfaces', 'updateInterfaces')->name('devices.interfaces.update');
        Route::delete('/devices/{device}/interfaces/{deviceInterface}', 'destroyInterface')->name('devices.interfaces.destroy');
        Route::put('/devices/edit/{device}', 'update')->name('devices.edit');
        Route::put('/devices/refresh-scope-id/{device}', 'refreshScopeId')->name('devices.refresh-scope-id');
        Route::delete('/devices/{device}', 'destroy')->name('devices.destroy');
    });

    Route::controller(SiteController::class)->group(function () {
        Route::get('/sites', 'index')->name('sites.index');
    });

    Route::controller(LicensingController::class)->group(function () {
        Route::get('/licensing', 'index')->name('licensing.index');
        Route::post('/licensing/renew', 'renew')->name('licensing.renew');
        Route::post('/licensing/assign', 'assign')->name('licensing.assign');
        Route::post('/licensing/unassign', 'unassign')->name('licensing.unassign');
        Route::post('/licensing/remove', 'removeFromWorkspace')->name('licensing.remove');
    });

    Route::controller(CentralApiExplorerController::class)->group(function () {
        Route::get('/central-api', 'index')->name('central-api.index');
        Route::post('/central-api/execute', 'execute')->name('central-api.execute');
    });

    Route::controller(DispatchController::class)->group(function () {
        Route::get('/dispatcher/dispatch/{task}', 'dispatch')->name('dispatcher.dispatch');
    });

    Route::controller(TaskController::class)->group(function () {
        Route::get('/tasks', 'index')->name('tasks.index');
        Route::get('/tasks/{task}', 'show')->name('tasks.show');
        Route::get('/tasks/{task}/check', 'check')->name('tasks.check');
        Route::get('/tasks/{task}/remediation-check', 'remediationCheck')->name('tasks.remediation_check');
        Route::get('/tasks/{task}/remediation-check/step/{step}', 'remediationCheckStep')->name('tasks.remediation_check.step');
        Route::post('/tasks/deployment/{deployment}/check-central-group', 'checkCentralGroup')->name('tasks.check_central_group');
        Route::post('/tasks/deployment/{deployment}/check-central-sites', 'checkCentralSites')->name('tasks.check_central_sites');
        Route::post('/tasks/deployment/{deployment}/force-update-site-scope-ids', 'forceUpdateSiteScopeIds')->name('tasks.force_update_site_scope_ids');
        Route::post('/tasks/deployment/{deployment}', 'store')->name('tasks.store');
        Route::post('/tasks/force_restart/{task}', 'force_restart')->name('tasks.force_restart');
        Route::post('/tasks/{task}/relaunch', 'relaunch')->name('tasks.relaunch');
        Route::post('/tasks/{task}/relaunch-failed-verification', 'relaunchFailedVerification')->name('tasks.relaunch_failed_verification');
        Route::post('/tasks/{task}/clear-queue', 'clearQueue')->name('tasks.clear_queue');
        Route::patch('/tasks/{task}', 'cancel')->name('tasks.cancel');
        Route::delete('/tasks/{task}', 'destroy')->name('tasks.destroy');
    });
});

require __DIR__.'/settings.php';
