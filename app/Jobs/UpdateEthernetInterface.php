<?php

namespace App\Jobs;

use App\Helper\ArrayHelper;
use App\Models\DeviceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use App\Helper\CentralAPIHelper;

class UpdateEthernetInterface implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $deviceInterface)
    {
        //
    }
    public static function build_switchport_from_device_interface(DeviceInterface $deviceInterface)
    {
        $switch_port = [];
        $stp_profile = [];

        if ($deviceInterface->switch_port !== null) {
            $switch_port = ArrayHelper::take_only_keys(
                ['access-vlan', 'native-vlan', 'trunk-vlan-all', 'interface-mode', 'trunk-vlan-ranges'],
                ArrayHelper::replace_keys(
                    ArrayHelper::replace_underscores_with_dashes(array_keys($deviceInterface->switch_port->toArray())),
                    array_values($deviceInterface->switch_port->toArray())
                )
            );
        }
        if ($deviceInterface->stp_profile !== null) {
            $stp_profile = ArrayHelper::take_only_keys(
                ['admin-edge-port', 'admin-edge-port-trunk', 'bpdu-guard', 'loop-guard'],
                ArrayHelper::replace_keys(
                    ArrayHelper::replace_underscores_with_dashes(array_keys($deviceInterface->stp_profile->toArray())),
                    array_values($deviceInterface->stp_profile->toArray())
                )
            );
        }

        $switchport_rest_body = [
            'interface' => $deviceInterface->interface,
            'switchport' => $switch_port,
            'portchannel-lag' => $deviceInterface->portchannel_lag,
            'stp' => $stp_profile,
        ];

        return array_filter($switchport_rest_body, fn ($value) => $value !== []);
    }

    public function patch_ethernet_interface(CentralAPIHelper $centralAPIHelper, DeviceInterface $deviceInterface)
    {
        $interface_rest_body = static::build_switchport_from_device_interface($deviceInterface);

        if (! $centralAPIHelper->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($centralAPIHelper->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device_function' => $deviceInterface->device->device_function,
                ])->withBody(json_encode($interface_rest_body))
                ->patch($centralAPIHelper->client->base_url.$centralAPIHelper->interfaces['interface_ethernet'].$deviceInterface->interface);

            return $response;
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = $this->patch_ethernet_interface($this->deviceInterface);
    }
}
