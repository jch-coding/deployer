<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeploymentController;
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
});

require __DIR__.'/settings.php';
