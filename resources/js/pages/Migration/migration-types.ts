import type { MigrationDevice, MigrationLldpNeighbor } from '@/lib/migration-csv';

export type SiteOption = {
    siteId: string;
    siteName: string;
};

export type WlanProfile = {
    ssid_profile_name: string;
    raw_vlan: string | null;
    vlan_name: string | null;
    body: Record<string, unknown>;
    warnings: string[];
};

export type ParsedController = {
    controller_name: string;
    devices: MigrationDevice[];
    lldp_neighbors: MigrationLldpNeighbor[];
    wlan_profiles: WlanProfile[];
};

export type DeployResult = {
    ssid: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

export type NamedVlanDeployResult = {
    name: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

export type DeployStepStatus = 'pending' | 'running' | 'success' | 'error' | 'skipped';

export type DeployStep = {
    key: string;
    label: string;
    status: DeployStepStatus;
    message?: string;
};

export type DeployProgress = {
    current: number;
    total: number;
    percent: number;
    message: string;
};

export type DeployStepResponse = {
    progress: DeployProgress;
    step: {
        key: string;
        label: string;
        status: 'success' | 'error' | 'skipped';
        message: string;
    };
    partial: {
        deploy_results: DeployResult[];
        named_vlan_deploy_results: NamedVlanDeployResult[];
    };
    context: {
        named_vlan_profiles: Array<Record<string, unknown>>;
    };
};

export function deployStatusVariant(
    status: DeployResult['status'] | NamedVlanDeployResult['status'],
): 'default' | 'destructive' | 'secondary' {
    switch (status) {
        case 'success':
            return 'default';
        case 'error':
            return 'destructive';
        default:
            return 'secondary';
    }
}

export function isFreezerSite(siteName: string): boolean {
    return siteName.includes('Freezer') && !siteName.includes('Hub-Freezer');
}

export function buildInitialDeploySteps(profiles: WlanProfile[], isFreezer: boolean): DeployStep[] {
    const steps: DeployStep[] = profiles.map((profile) => ({
        key: `wlan-${profile.ssid_profile_name}`,
        label: `Deploy WLAN profile: ${profile.ssid_profile_name}`,
        status: 'pending',
    }));

    if (isFreezer) {
        steps.push({
            key: 'named-vlan-fetch',
            label: 'Fetch named VLAN profiles from Central',
            status: 'pending',
        });
    }

    return steps;
}

export function profileSelectionKey(controllerName: string, profileName: string): string {
    return `${controllerName}:${profileName}`;
}

export function buildDeployProfilePayload(
    profile: WlanProfile,
    vlanOverride?: string,
): { ssid_profile_name: string; body: Record<string, unknown> } {
    const trimmedOverride = vlanOverride?.trim() ?? '';
    const resolvedVlanName =
        trimmedOverride !== ''
            ? trimmedOverride
            : (profile.vlan_name ?? String(profile.body['vlan-name'] ?? '')).trim();

    return {
        ssid_profile_name: profile.ssid_profile_name,
        body: {
            ...profile.body,
            'vlan-name': resolvedVlanName,
        },
    };
}
