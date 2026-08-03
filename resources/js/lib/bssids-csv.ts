export type BssidRow = {
    bssid: string;
    wlanName: string;
    radioNumber: number | null;
    radioMacAddress: string;
    macAddress: string;
    clientCount: number | null;
    siteName: string;
    siteId: string;
    clusterId: string;
    deviceName: string;
    serialNumber: string;
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

function nullableToString(value: number | null): string {
    return value === null ? '' : String(value);
}

export function downloadBssidsCsv(bssids: BssidRow[], serial: string): void {
    downloadCsv(
        `bssids-${sanitizeSerialForFilename(serial)}.csv`,
        [
            'bssid',
            'wlanName',
            'radioNumber',
            'radioMacAddress',
            'macAddress',
            'clientCount',
            'siteName',
            'siteId',
            'clusterId',
            'deviceName',
            'serialNumber',
        ],
        bssids.map((row) => [
            row.bssid,
            row.wlanName,
            nullableToString(row.radioNumber),
            row.radioMacAddress,
            row.macAddress,
            nullableToString(row.clientCount),
            row.siteName,
            row.siteId,
            row.clusterId,
            row.deviceName,
            row.serialNumber,
        ]),
    );
}

export type SiteBssidRow = {
    ap_name: string;
    ap_mac: string;
};

export function downloadSiteBssidsCsv(bssids: SiteBssidRow[], siteLabel: string): void {
    downloadCsv(
        `site-bssids-${sanitizeSerialForFilename(siteLabel)}.csv`,
        ['ap_name', 'ap_mac'],
        bssids.map((row) => [row.ap_name, row.ap_mac]),
    );
}
