<?php

namespace App\Services\Central;

use Illuminate\Http\Request;

class ClassicWebhookHmacVerifier
{
    public function verify(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Central-Signature', '');
        if ($signature === '' || $secret === '') {
            return false;
        }

        $service = (string) $request->header('X-Central-Service', '');
        $deliveryId = (string) $request->header('X-Central-Delivery-ID', '');
        $timestamp = (string) $request->header('X-Central-Delivery-Timestamp', '');

        $message = $request->getContent().$service.$deliveryId.$timestamp;
        $expected = base64_encode(hash_hmac('sha256', $message, $secret, true));

        return hash_equals($expected, $signature);
    }
}
