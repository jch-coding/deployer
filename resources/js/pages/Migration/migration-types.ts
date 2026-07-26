import type { MigrationDevice, MigrationLldpNeighbor } from '@/lib/migration-csv';

export type SiteOption = {
    siteId: string;
    siteName: string;
};

export type ScopeOption = {
    scopeId: string;
    scopeName: string;
};

export type AuthServerScopeType = 'site-collection' | 'site' | 'device-group';

export type AuthServer = {
    name: string;
    host: string | null;
    has_coa: boolean;
    body: Record<string, unknown>;
    warnings: string[];
};

export type ServerGroup = {
    name: string;
    servers: Array<{ 'server-name': string; position: number }>;
    body: Record<string, unknown>;
    associated_essids: string[];
    warnings: string[];
};

export type WlanProfile = {
    ssid_profile_name: string;
    raw_vlan: string | null;
    vlan_name: string | null;
    body: Record<string, unknown>;
    warnings: string[];
};

export type NetDestinationEntry = {
    type: 'host' | 'network' | 'name';
    value?: string;
    subnet?: string;
};

export type NetDestination = {
    name: string;
    invert: boolean;
    entries: NetDestinationEntry[];
};

export type NetService = {
    name: string;
    protocol: string | null;
    values: string[];
    alg: string | null;
};

export type AccessListEndpoint = {
    type: 'user' | 'any' | 'host' | 'alias' | 'network';
    value?: string;
    subnet?: string;
    resolved?: NetDestination | null;
};

export type AccessListService = {
    type: 'any' | 'tcp' | 'udp' | 'svc' | 'app' | 'other';
    ports?: string[];
    name?: string;
    raw?: string;
    resolved?: NetService | null;
};

export type AccessListRule = {
    source: AccessListEndpoint;
    destination: AccessListEndpoint;
    service: AccessListService;
    action: string;
    other: string;
};

export type AccessList = {
    name: string;
    rules: AccessListRule[];
    warnings: string[];
};

export type UserRole = {
    name: string;
    access_lists: AccessList[];
    warnings: string[];
};

export type ParsedController = {
    controller_name: string;
    devices: MigrationDevice[];
    lldp_neighbors: MigrationLldpNeighbor[];
    auth_servers: AuthServer[];
    server_groups: ServerGroup[];
    wlan_profiles: WlanProfile[];
    user_roles: UserRole[];
};

export type DeployResult = {
    ssid: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

export type AuthServerDeployResult = {
    name: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

export type ServerGroupDeployResult = {
    name: string;
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

export type AuthServerDeployStepResponse = {
    progress: DeployProgress;
    step: {
        key: string;
        label: string;
        status: 'success' | 'error' | 'skipped';
        message: string;
    };
    partial: {
        deploy_results: AuthServerDeployResult[];
    };
};

export type ServerGroupDeployStepResponse = {
    progress: DeployProgress;
    step: {
        key: string;
        label: string;
        status: 'success' | 'error' | 'skipped';
        message: string;
    };
    partial: {
        deploy_results: ServerGroupDeployResult[];
    };
};

export function deployStatusVariant(
    status:
        | DeployResult['status']
        | NamedVlanDeployResult['status']
        | AuthServerDeployResult['status']
        | ServerGroupDeployResult['status'],
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

export function buildInitialAuthServerDeploySteps(servers: AuthServer[]): DeployStep[] {
    return servers.map((server) => ({
        key: `auth-server-${server.name}`,
        label: `Deploy auth server: ${server.name}`,
        status: 'pending',
    }));
}

export function buildInitialServerGroupDeploySteps(groups: ServerGroup[]): DeployStep[] {
    return groups.map((group) => ({
        key: `server-group-${group.name}`,
        label: `Deploy server group: ${group.name}`,
        status: 'pending',
    }));
}

export function profileSelectionKey(controllerName: string, profileName: string): string {
    return `${controllerName}:${profileName}`;
}

export function authServerSelectionKey(controllerName: string, serverName: string): string {
    return `${controllerName}:${serverName}`;
}

export function serverGroupSelectionKey(controllerName: string, groupName: string): string {
    return `${controllerName}:${groupName}`;
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

export function buildDeployAuthServerPayload(
    server: AuthServer,
): { name: string; body: Record<string, unknown> } {
    return {
        name: server.name,
        body: { ...server.body },
    };
}

export function buildDeployServerGroupPayload(
    group: ServerGroup,
): { name: string; body: Record<string, unknown> } {
    return {
        name: group.name,
        body: { ...group.body },
    };
}
