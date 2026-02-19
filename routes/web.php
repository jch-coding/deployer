<?php

use App\Http\Controllers\ClientController;
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
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::put('/clients/edit/{client}', [ClientController::class, 'update'])->name('clients.edit');
    Route::put('/clients/current/{client}', [ClientController::class, 'updateCurrent'])->name('clients.current');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::post('/clients/test_central_creds/{client}', [ClientController::class, 'testCentralCreds'])->name('clients.test_central_creds');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

});

require __DIR__.'/settings.php';
