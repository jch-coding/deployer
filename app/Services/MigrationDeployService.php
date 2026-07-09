<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;

class MigrationDeployService
{
    public function __construct(
        private MigrationNamedVlanService $migrationNamedVlanService,
    ) {}

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $profiles
     * @param  array<int, array<string, mixed>>|null  $namedVlanProfiles
     */
    public function totalSteps(array $profiles, bool $isFreezer, ?array $namedVlanProfiles = null): int
    {
        $wlanCount = count($profiles);

        if (! $isFreezer) {
            return $wlanCount;
        }

        if ($namedVlanProfiles === null) {
            return $wlanCount + 1;
        }

        return $wlanCount + 1 + count(
            $this->migrationNamedVlanService->deployableNamedVlanProfiles($namedVlanProfiles),
        );
    }

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $profiles
     * @param  array<string, mixed>  $context
     * @return array{
     *     progress: array{current: int, total: int, percent: int, message: string},
     *     step: array{key: string, label: string, status: string, message: string},
     *     partial: array{
     *         deploy_results: array<int, array{ssid: string, status: string, message: string}>,
     *         named_vlan_deploy_results: array<int, array{name: string, status: string, message: string}>
     *     },
     *     context: array{named_vlan_profiles: array<int, array<string, mixed>>}
     * }
     */
    public function runStep(
        CentralAPIHelper $helper,
        string $scopeId,
        array $profiles,
        int $step,
        array $context,
        bool $isFreezer,
    ): array {
        $namedVlanProfiles = $context['named_vlan_profiles'] ?? null;
        $total = $this->totalSteps($profiles, $isFreezer, is_array($namedVlanProfiles) ? $namedVlanProfiles : null);
        $wlanCount = count($profiles);

        if ($step < 0 || $step >= $total) {
            abort(404);
        }

        $responseContext = [
            'named_vlan_profiles' => is_array($namedVlanProfiles) ? $namedVlanProfiles : [],
        ];

        if ($step < $wlanCount) {
            return $this->runWlanProfileStep(
                $helper,
                $scopeId,
                $profiles[$step],
                $step,
                $total,
                $responseContext,
            );
        }

        if (! $isFreezer) {
            abort(404);
        }

        if ($step === $wlanCount) {
            return $this->runFetchNamedVlansStep($helper, $scopeId, $profiles, $step, $responseContext);
        }

        $deployableProfiles = $this->migrationNamedVlanService->deployableNamedVlanProfiles(
            is_array($namedVlanProfiles) ? $namedVlanProfiles : [],
        );
        $vlanIndex = $step - $wlanCount - 1;

        if (! array_key_exists($vlanIndex, $deployableProfiles)) {
            abort(404);
        }

        $profile = $deployableProfiles[$vlanIndex];
        $profileName = trim((string) ($profile['name'] ?? ''));
        $result = $this->migrationNamedVlanService->deploySingleOffsetNamedVlan($helper, $scopeId, $profile);
        $updatedTotal = $this->totalSteps($profiles, true, $responseContext['named_vlan_profiles']);

        return [
            'progress' => $this->buildProgress(
                $step + 1,
                $updatedTotal,
                "Deployed named VLAN {$profileName} with +200 offset",
            ),
            'step' => [
                'key' => 'named-vlan-'.$profileName,
                'label' => 'Deploy named VLAN: '.$profileName.' (+200 offset)',
                'status' => $result['status'],
                'message' => $result['message'],
            ],
            'partial' => [
                'deploy_results' => [],
                'named_vlan_deploy_results' => [$result],
            ],
            'context' => $responseContext,
        ];
    }

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $profiles
     * @return array{
     *     deploy_results: array<int, array{ssid: string, status: string, message: string}>,
     *     named_vlan_deploy_results: array<int, array{name: string, status: string, message: string}>
     * }
     */
    public function deployAll(
        CentralAPIHelper $helper,
        string $scopeId,
        array $profiles,
        bool $isFreezer,
    ): array {
        $deployResults = [];
        $namedVlanDeployResults = [];
        $context = ['named_vlan_profiles' => []];
        $total = $this->totalSteps($profiles, $isFreezer);

        for ($step = 0; $step < $total; $step++) {
            $result = $this->runStep($helper, $scopeId, $profiles, $step, $context, $isFreezer);
            $deployResults = array_merge($deployResults, $result['partial']['deploy_results']);
            $namedVlanDeployResults = array_merge(
                $namedVlanDeployResults,
                $result['partial']['named_vlan_deploy_results'],
            );
            $context = $result['context'];
            $total = $result['progress']['total'];
        }

        return [
            'deploy_results' => $deployResults,
            'named_vlan_deploy_results' => $namedVlanDeployResults,
        ];
    }

    /**
     * @param  array{ssid_profile_name: string, body: array<string, mixed>}  $profile
     * @param  array{named_vlan_profiles: array<int, array<string, mixed>>}  $responseContext
     * @return array{
     *     progress: array{current: int, total: int, percent: int, message: string},
     *     step: array{key: string, label: string, status: string, message: string},
     *     partial: array{
     *         deploy_results: array<int, array{ssid: string, status: string, message: string}>,
     *         named_vlan_deploy_results: array<int, array{name: string, status: string, message: string}>
     *     },
     *     context: array{named_vlan_profiles: array<int, array<string, mixed>>}
     * }
     */
    private function runWlanProfileStep(
        CentralAPIHelper $helper,
        string $scopeId,
        array $profile,
        int $step,
        int $total,
        array $responseContext,
    ): array {
        $ssidProfileName = $profile['ssid_profile_name'];
        $body = $profile['body'];
        $result = $this->deployWlanProfile($helper, $scopeId, $ssidProfileName, $body);

        return [
            'progress' => $this->buildProgress(
                $step + 1,
                $total,
                "Deployed WLAN profile {$ssidProfileName}",
            ),
            'step' => [
                'key' => 'wlan-'.$ssidProfileName,
                'label' => 'Deploy WLAN profile: '.$ssidProfileName,
                'status' => $result['status'],
                'message' => $result['message'],
            ],
            'partial' => [
                'deploy_results' => [$result],
                'named_vlan_deploy_results' => [],
            ],
            'context' => $responseContext,
        ];
    }

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $profiles
     * @param  array{named_vlan_profiles: array<int, array<string, mixed>>}  $responseContext
     * @return array{
     *     progress: array{current: int, total: int, percent: int, message: string},
     *     step: array{key: string, label: string, status: string, message: string},
     *     partial: array{
     *         deploy_results: array<int, array{ssid: string, status: string, message: string}>,
     *         named_vlan_deploy_results: array<int, array{name: string, status: string, message: string}>
     *     },
     *     context: array{named_vlan_profiles: array<int, array<string, mixed>>}
     * }
     */
    private function runFetchNamedVlansStep(
        CentralAPIHelper $helper,
        string $scopeId,
        array $profiles,
        int $step,
        array $responseContext,
    ): array {
        $fetch = $this->migrationNamedVlanService->fetchNamedVlanProfiles($helper, $scopeId);

        if ($fetch['error'] !== null) {
            $responseContext['named_vlan_profiles'] = [];
            $result = [
                'name' => '*',
                'status' => 'error',
                'message' => $fetch['error'],
            ];
            $updatedTotal = $this->totalSteps($profiles, true, $responseContext['named_vlan_profiles']);

            return [
                'progress' => $this->buildProgress(
                    $step + 1,
                    $updatedTotal,
                    'Failed to fetch named VLAN profiles from Central',
                ),
                'step' => [
                    'key' => 'named-vlan-fetch',
                    'label' => 'Fetch named VLAN profiles from Central',
                    'status' => 'error',
                    'message' => $fetch['error'],
                ],
                'partial' => [
                    'deploy_results' => [],
                    'named_vlan_deploy_results' => [$result],
                ],
                'context' => $responseContext,
            ];
        }

        $responseContext['named_vlan_profiles'] = $fetch['profiles'];
        $deployableCount = count(
            $this->migrationNamedVlanService->deployableNamedVlanProfiles($fetch['profiles']),
        );
        $updatedTotal = $this->totalSteps($profiles, true, $responseContext['named_vlan_profiles']);

        if ($deployableCount === 0) {
            $result = [
                'name' => '*',
                'status' => 'skipped',
                'message' => 'No named VLAN profiles returned from Central',
            ];

            return [
                'progress' => $this->buildProgress(
                    $step + 1,
                    $updatedTotal,
                    'No named VLAN profiles to deploy',
                ),
                'step' => [
                    'key' => 'named-vlan-fetch',
                    'label' => 'Fetch named VLAN profiles from Central',
                    'status' => 'skipped',
                    'message' => $result['message'],
                ],
                'partial' => [
                    'deploy_results' => [],
                    'named_vlan_deploy_results' => [$result],
                ],
                'context' => $responseContext,
            ];
        }

        return [
            'progress' => $this->buildProgress(
                $step + 1,
                $updatedTotal,
                "Fetched {$deployableCount} named VLAN profile(s) from Central",
            ),
            'step' => [
                'key' => 'named-vlan-fetch',
                'label' => 'Fetch named VLAN profiles from Central',
                'status' => 'success',
                'message' => "Found {$deployableCount} named VLAN profile(s) to deploy",
            ],
            'partial' => [
                'deploy_results' => [],
                'named_vlan_deploy_results' => [],
            ],
            'context' => $responseContext,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ssid: string, status: string, message: string}
     */
    private function deployWlanProfile(
        CentralAPIHelper $helper,
        string $scopeId,
        string $ssidProfileName,
        array $body,
    ): array {
        $passphrase = $body['personal-security']['wpa-passphrase'] ?? null;
        $vlanName = $body['vlan-name'] ?? null;

        if ($passphrase === null || $passphrase === '' || $vlanName === null || $vlanName === '') {
            return [
                'ssid' => $ssidProfileName,
                'status' => 'skipped',
                'message' => 'Missing required wpa-passphrase or vlan-name',
            ];
        }

        $queryParameters = [
            'object-type' => 'LOCAL',
            'view-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => 'CAMPUS_AP',
        ];

        $response = $helper->post_wlan_ssid_profile($ssidProfileName, $queryParameters, $body);

        if (is_array($response) && array_key_exists('error', $response)) {
            return [
                'ssid' => $ssidProfileName,
                'status' => 'error',
                'message' => (string) $response['error'],
            ];
        }

        if ($response->successful()) {
            return [
                'ssid' => $ssidProfileName,
                'status' => 'success',
                'message' => 'Deployed successfully',
            ];
        }

        return [
            'ssid' => $ssidProfileName,
            'status' => 'error',
            'message' => $response->body() ?: 'Request failed with status '.$response->status(),
        ];
    }

    /**
     * @return array{current: int, total: int, percent: int, message: string}
     */
    private function buildProgress(int $current, int $total, string $message): array
    {
        $percent = $total > 0 ? (int) round(($current / $total) * 100) : 0;

        return [
            'current' => $current,
            'total' => $total,
            'percent' => min(100, $percent),
            'message' => $message,
        ];
    }
}
