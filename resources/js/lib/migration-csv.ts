export type MigrationDevice = {
    name: string;
    serial: string;
    mac: string;
    controller?: string;
};

export type MigrationLldpNeighbor = {
    switch: string;
    ports: string[];
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

function sanitizeControllerNameForFilename(controllerName: string): string {
    const sanitized = controllerName.replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');

    return sanitized === '' ? 'controller' : sanitized;
}

function csvFilename(base: string, controllerName?: string): string {
    if (controllerName === undefined || controllerName.trim() === '') {
        return `${base}.csv`;
    }

    return `${base}-${sanitizeControllerNameForFilename(controllerName)}.csv`;
}

export function downloadMigrationDevicesCsv(
    devices: MigrationDevice[],
    controllerName?: string,
): void {
    const includeControllerColumn = devices.some((device) => device.controller !== undefined);

    downloadCsv(
        csvFilename('migration-devices', controllerName),
        includeControllerColumn
            ? ['name', 'serial', 'mac', 'controller']
            : ['name', 'serial', 'mac'],
        devices.map((device) =>
            includeControllerColumn
                ? [device.name, device.serial, device.mac, device.controller ?? '']
                : [device.name, device.serial, device.mac],
        ),
    );
}

export function downloadMigrationLldpCsv(
    neighbors: MigrationLldpNeighbor[],
    controllerName?: string,
): void {
    downloadCsv(
        csvFilename('migration-lldp-neighbors', controllerName),
        ['switch', 'ports'],
        neighbors.map((neighbor) => [
            neighbor.switch,
            neighbor.ports.join(','),
        ]),
    );
}
