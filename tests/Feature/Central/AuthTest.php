<?php

use App\Http\Controllers\CentralController;
use App\Models\Client;
use App\Models\User;

it('can authenticate using a static method', function () {
    Http::fake(function ($request) {
        return Http::response([
            'access_token' => 'test_token'
        ]);
    });
    $access_token = CentralController::getAccessToken('test_client_id', 'test_client_secret');
    expect($access_token)->toBe('test_token');
});

it('returns failed_to_get_token as a string on failure', function () {
    Http::fake(function ($request) {
        return Http::response('failed', 401);
    });

    $access_token = CentralController::getAccessToken('test_client_id', 'test_client_secret');
    expect($access_token)->toBe('failed_to_get_token');
});
