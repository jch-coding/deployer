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

export function downloadMigrationDevicesCsv(devices: MigrationDevice[]): void {
    downloadCsv(
        'migration-devices.csv',
        ['name', 'serial', 'mac', 'controller'],
        devices.map((device) => [
            device.name,
            device.serial,
            device.mac,
            device.controller ?? '',
        ]),
    );
}

export function downloadMigrationLldpCsv(neighbors: MigrationLldpNeighbor[]): void {
    downloadCsv(
        'migration-lldp-neighbors.csv',
        ['switch', 'ports'],
        neighbors.map((neighbor) => [
            neighbor.switch,
            neighbor.ports.join(','),
        ]),
    );
}
