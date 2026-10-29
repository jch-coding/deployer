export type EventCsvRow = {
    timeAt: string;
    eventName: string;
    category: string;
    severity: string;
    sourceType: string;
    sourceName: string;
    serialNumber: string;
    eventId: string;
    eventIdentifier: string;
    description: string;
    reason: string;
    clientMacAddress: string;
    deviceMacAddress: string;
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

function csvFilename(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');
    const stamp = [
        now.getFullYear(),
        pad(now.getMonth() + 1),
        pad(now.getDate()),
        '-',
        pad(now.getHours()),
        pad(now.getMinutes()),
        pad(now.getSeconds()),
    ].join('');

    return `central-events-${stamp}.csv`;
}

export function downloadEventsCsv(events: EventCsvRow[]): void {
    downloadCsv(
        csvFilename(),
        [
            'timeAt',
            'eventName',
            'category',
            'severity',
            'sourceType',
            'sourceName',
            'serialNumber',
            'eventId',
            'eventIdentifier',
            'description',
            'reason',
            'clientMacAddress',
            'deviceMacAddress',
        ],
        events.map((event) => [
            event.timeAt,
            event.eventName,
            event.category,
            event.severity,
            event.sourceType,
            event.sourceName,
            event.serialNumber,
            event.eventId,
            event.eventIdentifier,
            event.description,
            event.reason,
            event.clientMacAddress,
            event.deviceMacAddress,
        ]),
    );
}
