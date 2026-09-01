import type { MacAddressSearchMatch } from '@/lib/mac-address-search';

function escapeCsvValue(value: string): string {
    if (value.includes(',') || value.includes('"') || value.includes('\n')) {
        return `"${value.replace(/"/g, '""')}"`;
    }

    return value;
}

function sanitizeLabelForFilename(label: string): string {
    const sanitized = label
        .replace(/[^a-zA-Z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return sanitized === '' ? 'site' : sanitized;
}

export function downloadMacAddressSearchCsv(
    matches: MacAddressSearchMatch[],
    siteLabel: string,
): void {
    const headers = ['switch', 'serial', 'mac', 'vlan', 'type', 'port'];
    const rows = matches.map((match) => [
        match.deviceName,
        match.serial,
        match.mac,
        match.vlan,
        match.type,
        match.port,
    ]);

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
    anchor.download = `site-mac-search-${sanitizeLabelForFilename(siteLabel)}.csv`;
    anchor.rel = 'noopener';
    anchor.click();
    URL.revokeObjectURL(url);
}
