<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CentralController extends Controller
{
    public $client;
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public static function getAccessToken($client_id, $client_secret)
    {
        $response = Http::asForm()->post("https://sso.common.cloud.hpe.com/as/token.oauth2", [
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]);

        if($response->ok()) {
            return $response->json('access_token');
        }
        return 'failed_to_get_token';
    }
}
