import { isAccessPointDevice } from '@/lib/is-access-point';
import { macAddressHex, normalizeMacAddress } from '@/lib/mac-address';

export type MacAddressTableRow = {
    mac: string;
    vlan: string;
    type: string;
    port: string;
};

export type MacAddressTableParseResult = {
    rows: MacAddressTableRow[];
    ageTime: string | null;
    count: number | null;
    raw: string;
};

const MAC_AGE_TIME_PATTERN = /MAC age-time\s*:\s*(.+)/i;
const MAC_COUNT_PATTERN = /Number of MAC addresses\s*:\s*(\d+)/i;
const SEPARATOR_LINE_PATTERN = /^[-=\s]+$/;

function sliceColumn(line: string, start: number, end: number | null): string {
    const slice = end === null ? line.slice(start) : line.slice(start, end);

    return slice.trim();
}

function findHeaderLine(lines: string[]): string | null {
    return (
        lines.find(
            (line) =>
                line.includes('MAC Address') &&
                line.includes('VLAN') &&
                line.includes('Type') &&
                line.includes('Port'),
        ) ?? null
    );
}

function parseDataRow(
    line: string,
    columnStarts: { mac: number; vlan: number; type: number; port: number },
): MacAddressTableRow | null {
    if (line.trim() === '' || SEPARATOR_LINE_PATTERN.test(line)) {
        return null;
    }

    const mac = sliceColumn(line, columnStarts.mac, columnStarts.vlan);
    const vlan = sliceColumn(line, columnStarts.vlan, columnStarts.type);
    const type = sliceColumn(line, columnStarts.type, columnStarts.port);
    const port = sliceColumn(line, columnStarts.port, null);

    if (mac === '' || vlan === '' || type === '' || port === '') {
        return null;
    }

    return { mac, vlan, type, port };
}

export function parseMacAddressTableOutput(
    output: string,
): MacAddressTableParseResult {
    const raw = output;
    const lines = output.split(/\r?\n/);

    const ageTimeMatch = output.match(MAC_AGE_TIME_PATTERN);
    const countMatch = output.match(MAC_COUNT_PATTERN);

    const ageTime = ageTimeMatch?.[1]?.trim() ?? null;
    const count = countMatch?.[1] ? Number.parseInt(countMatch[1], 10) : null;

    const headerLine = findHeaderLine(lines);
    if (headerLine === null) {
        return { rows: [], ageTime, count, raw };
    }

    const macStart = headerLine.indexOf('MAC Address');
    const vlanStart = headerLine.indexOf('VLAN');
    const typeStart = headerLine.indexOf('Type');
    const portStart = headerLine.indexOf('Port');

    if (
        macStart === -1 ||
        vlanStart === -1 ||
        typeStart === -1 ||
        portStart === -1
    ) {
        return { rows: [], ageTime, count, raw };
    }

    const columnStarts = {
        mac: macStart,
        vlan: vlanStart,
        type: typeStart,
        port: portStart,
    };

    const headerIndex = lines.indexOf(headerLine);
    const rows: MacAddressTableRow[] = [];

    for (let index = headerIndex + 1; index < lines.length; index += 1) {
        const line = lines[index] ?? '';
        const row = parseDataRow(line, columnStarts);
        if (row !== null) {
            rows.push(row);
        }
    }

    return { rows, ageTime, count, raw };
}

export function isMacLikeSearchQuery(query: string): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return false;
    }

    return /^[0-9a-fA-F:.\-\s]+$/.test(trimmed);
}

export function matchesMacAddressTableSearch(
    row: MacAddressTableRow,
    query: string,
): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return true;
    }

    const needle = trimmed.toLowerCase();

    if (row.mac.toLowerCase().includes(needle)) {
        return true;
    }

    if (row.vlan.toLowerCase().includes(needle)) {
        return true;
    }

    if (row.type.toLowerCase().includes(needle)) {
        return true;
    }

    if (row.port.toLowerCase().includes(needle)) {
        return true;
    }

    if (isMacLikeSearchQuery(trimmed)) {
        const queryHex = macAddressHex(trimmed);
        if (queryHex !== '') {
            const rowHex = macAddressHex(row.mac);
            if (rowHex.includes(queryHex)) {
                return true;
            }
        }
    }

    return false;
}

export function filterMacAddressTableRows(
    rows: MacAddressTableRow[],
    query: string,
): MacAddressTableRow[] {
    return rows.filter((row) => matchesMacAddressTableSearch(row, query));
}

export type SwitchLikeDevice = {
    deviceType?: string;
    device_type?: string;
    deviceFunction?: string;
    device_function?: string;
    model?: string;
};

export function isSwitchDevice(device: SwitchLikeDevice): boolean {
    const deviceType = (device.deviceType ?? device.device_type ?? '')
        .trim()
        .toUpperCase();

    if (deviceType === 'GATEWAY') {
        return false;
    }

    if (
        isAccessPointDevice({
            device_type: device.deviceType ?? device.device_type,
            device_function: device.deviceFunction ?? device.device_function,
            model: device.model,
        })
    ) {
        return false;
    }

    return deviceType === 'SWITCH';
}

export function parseMacAddressLines(input: string): {
    macs: string[];
    invalidLines: string[];
} {
    const macs: string[] = [];
    const invalidLines: string[] = [];
    const seen = new Set<string>();

    const tokens = input
        .split(/[\n,]+/)
        .map((token) => token.trim())
        .filter((token) => token !== '');

    for (const token of tokens) {
        const normalized = normalizeMacAddress(token);
        if (normalized === null) {
            invalidLines.push(token);
            continue;
        }

        if (!seen.has(normalized)) {
            seen.add(normalized);
            macs.push(normalized);
        }
    }

    return { macs, invalidLines };
}

export function matchesAnyTargetMac(
    row: MacAddressTableRow,
    targetMacs: string[],
): boolean {
    if (targetMacs.length === 0) {
        return false;
    }

    const rowHex = macAddressHex(row.mac);
    const targetHexSet = new Set(
        targetMacs
            .map((mac) => macAddressHex(mac))
            .filter((hex) => hex.length === 12),
    );

    return targetHexSet.has(rowHex);
}
