<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Support\MacAddress;
use Illuminate\Support\Facades\Log;

class ExportMacToCentralNacAction
{
    /**
     * @param  list<string>  $staticTags
     */
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper, array $staticTags = []): ProvisioningStepResult
    {
        $mac = MacAddress::toCentralCsvFormat((string) ($device->mac_address ?? ''));
        if ($mac === null) {
            return ProvisioningStepResult::failed('Device is missing or has an invalid mac_address.');
        }

        $tags = array_values(array_filter(
            array_map(fn ($tag) => trim((string) $tag), $staticTags),
            fn (string $tag): bool => $tag !== ''
        ));

        $csv = $this->buildMacRegistrationCsv($mac, $tags);
        $result = $centralAPIHelper->importMacCsvFile($csv);

        if (($result['success'] ?? false) !== true) {
            $detail = (string) ($result['error'] ?? 'Central MAC CSV import failed.');
            Log::error('Failed to export MAC address to Central NAC', [
                'serial' => $device->serial,
                'mac' => $mac,
                'error' => $detail,
            ]);

            return ProvisioningStepResult::failed("Failed to export MAC {$mac} to Central NAC: {$detail}");
        }

        $label = trim((string) $device->serial) !== '' ? (string) $device->serial : $mac;
        $jobIds = $result['job_ids'] ?? [];
        $jobIdLog = $jobIds !== [] ? ' Job ID(s): '.implode(', ', $jobIds).'.' : '';

        return ProvisioningStepResult::completed("Exported MAC {$mac} ({$label}) to Central NAC.{$jobIdLog}");
    }

    /**
     * @param  list<string>  $tags
     */
    private function buildMacRegistrationCsv(string $mac, array $tags): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open temporary stream for MAC CSV.');
        }

        fputcsv($handle, ['MAC Address', 'Client Name', 'Enabled', 'Static Tags']);
        fputcsv($handle, [$mac, '', 'true', implode(', ', $tags)]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
