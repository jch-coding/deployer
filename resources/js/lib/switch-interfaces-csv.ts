export type SwitchInterfaceRow = {
    name: string;
    status: string;
    operStatus: string;
    neighbour: string;
    neighbourSerial: string;
    vlanMode: string;
    allowedVlanIds: number[];
    nativeVlan: string;
    poeClass: string;
    neighbourFamily: string;
    neighbourFunction: string;
    neighbourType: string;
    transceiverType: string;
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

function sanitizeSerialForFilename(serial: string): string {
    const sanitized = serial.replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');

    return sanitized === '' ? 'device' : sanitized;
}

export function formatAllowedVlanIds(allowedVlanIds: number[]): string {
    return allowedVlanIds.join(', ');
}

export function downloadSwitchInterfacesCsv(interfaces: SwitchInterfaceRow[], serial: string): void {
    downloadCsv(
        `interfaces-${sanitizeSerialForFilename(serial)}.csv`,
        [
            'name',
            'status',
            'operStatus',
            'neighbour',
            'neighbourSerial',
            'vlanMode',
            'allowedVlanIds',
            'nativeVlan',
            'poeClass',
            'neighbourFamily',
            'neighbourFunction',
            'neighbourType',
            'transceiverType',
        ],
        interfaces.map((iface) => [
            iface.name,
            iface.status,
            iface.operStatus,
            iface.neighbour,
            iface.neighbourSerial,
            iface.vlanMode,
            formatAllowedVlanIds(iface.allowedVlanIds),
            iface.nativeVlan,
            iface.poeClass,
            iface.neighbourFamily,
            iface.neighbourFunction,
            iface.neighbourType,
            iface.transceiverType,
        ]),
    );
}
