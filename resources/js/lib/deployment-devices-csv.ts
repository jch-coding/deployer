export type DeploymentDeviceCsvRow = {
    name: string;
    serial: string | number;
    mac_address?: string | null;
    controller_joined_ip?: string | null;
    model?: string | null;
    device_function: string;
    site?: string | null;
    group?: string | null;
};

function escapeCsvValue(value: string): string {
    if (value.includes(',') || value.includes('"') || value.includes('\n')) {
        return `"${value.replace(/"/g, '""')}"`;
    }

    return value;
}

function downloadCsv(filename: string, headers: string[], rows: string[][]): void {
    const lines = [
        headers.join(','),
        ...rows.map((row) => row.map(escapeCsvValue).join(',')),
    ];
    const blob = new Blob([`\uFEFF${lines.join('\n')}`], {
        type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.click();
    URL.revokeObjectURL(url);
}

function sanitizeDeploymentNameForFilename(deploymentName: string): string {
    const sanitized = deploymentName
        .replace(/[^a-zA-Z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return sanitized === '' ? 'deployment' : sanitized;
}

function csvFilename(deploymentName?: string): string {
    if (deploymentName === undefined || deploymentName.trim() === '') {
        return 'deployment-devices.csv';
    }

    return `deployment-${sanitizeDeploymentNameForFilename(deploymentName)}-devices.csv`;
}

function cellValue(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

export function downloadDeploymentDevicesCsv(
    devices: DeploymentDeviceCsvRow[],
    deploymentName?: string,
): void {
    const includeControllerJoinedIp = devices.some(
        (device) =>
            device.controller_joined_ip !== undefined &&
            device.controller_joined_ip !== null &&
            device.controller_joined_ip !== '',
    );

    const headers = [
        'name',
        'serial',
        'mac_address',
        ...(includeControllerJoinedIp ? ['controller_joined_ip'] : []),
        'model',
        'device_function',
        'site',
        'group',
    ];

    downloadCsv(
        csvFilename(deploymentName),
        headers,
        devices.map((device) => {
            const row = [
                device.name,
                cellValue(device.serial),
                cellValue(device.mac_address),
            ];
            if (includeControllerJoinedIp) {
                row.push(cellValue(device.controller_joined_ip));
            }
            row.push(
                cellValue(device.model),
                device.device_function,
                cellValue(device.site),
                cellValue(device.group),
            );
            return row;
        }),
    );
}
