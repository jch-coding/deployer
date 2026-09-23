import { matchesDeploymentDeviceFilter } from '@/lib/mac-address-table';

export type DeploymentIndexSearchDevice = {
    name: string;
    serial: string;
    mac_address: string | null;
    site: string | null;
    group: string | null;
    controller_joined_ip?: string | null;
};

export type DeploymentIndexSearchable = {
    name: string;
    devices: DeploymentIndexSearchDevice[];
};

function matchesDeviceFields(
    device: DeploymentIndexSearchDevice,
    query: string,
): boolean {
    if (
        matchesDeploymentDeviceFilter(
            {
                name: device.name,
                serial: device.serial,
                mac_address: device.mac_address ?? '',
            },
            query,
        )
    ) {
        return true;
    }

    const needle = query.trim().toLowerCase();

    if ((device.site ?? '').toLowerCase().includes(needle)) {
        return true;
    }

    if ((device.group ?? '').toLowerCase().includes(needle)) {
        return true;
    }

    const controllerJoinedIp = device.controller_joined_ip?.trim() ?? '';
    if (
        controllerJoinedIp !== '' &&
        controllerJoinedIp.toLowerCase().includes(needle)
    ) {
        return true;
    }

    return false;
}

/**
 * Case-insensitive match for a deployment by its name or any nested device
 * name, serial, MAC, site, group, or controller joined IP (when present).
 */
export function matchesDeploymentIndexSearch(
    deployment: DeploymentIndexSearchable,
    query: string,
): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return true;
    }

    if (deployment.name.toLowerCase().includes(trimmed.toLowerCase())) {
        return true;
    }

    return deployment.devices.some((device) =>
        matchesDeviceFields(device, trimmed),
    );
}

export function filterDeploymentsByIndexSearch<
    T extends DeploymentIndexSearchable,
>(deployments: T[], query: string): T[] {
    return deployments.filter((deployment) =>
        matchesDeploymentIndexSearch(deployment, query),
    );
}
