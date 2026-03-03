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
        Route::put('/clients/edit/{client}',  'update')->name('clients.edit');
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
    });

    Route::controller(DispatchController::class)->group( function () {
        Route::patch('/dispatcher/dispatch/{task}', 'dispatch')->name('dispatcher.dispatch');
    });

    Route::controller(TaskController::class)->group( function () {
        Route::post('/tasks/deployment/{deployment}', 'store')->name('tasks.store');
    });
});

require __DIR__.'/settings.php';
