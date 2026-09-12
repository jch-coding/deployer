export type DatapathSessionTableRow = {
    sourceIp: string;
    destinationIp: string;
    prot: string;
    sport: string;
    dport: string;
    cntr: string;
    prio: string;
    tos: string;
    age: string;
    destination: string;
    tage: string;
    packets: string;
    bytes: string;
    flags: string;
    offloadFlags: string;
};

export type DatapathSessionTableParseResult = {
    rows: DatapathSessionTableRow[];
    raw: string;
};

export type DatapathSessionTableFilters = {
    sourceIp: string;
    destinationIp: string;
    prot: string;
    sport: string;
    dport: string;
    cntr: string;
    prio: string;
    tos: string;
    age: string;
    destination: string;
    tage: string;
    packets: string;
    bytes: string;
    flags: string;
    offloadFlags: string;
};

export const emptyDatapathSessionTableFilters: DatapathSessionTableFilters = {
    sourceIp: '',
    destinationIp: '',
    prot: '',
    sport: '',
    dport: '',
    cntr: '',
    prio: '',
    tos: '',
    age: '',
    destination: '',
    tage: '',
    packets: '',
    bytes: '',
    flags: '',
    offloadFlags: '',
};

export const DATAPATH_SESSION_FLAG_NAMES: Readonly<Record<string, string>> = {
    A: 'Application Firewall Inspect',
    C: 'client',
    D: 'deny',
    E: 'Media Deep Inspect',
    F: 'fast age',
    G: 'media signal',
    H: 'high prio',
    I: 'Deep inspect',
    L: 'ALG session',
    M: 'mirror',
    N: 'dest NAT',
    O: 'SDN/Openflow',
    P: 'set prio',
    R: 'redirect',
    S: 'src NAT',
    T: 'set ToS',
    U: 'Locally destined',
    V: 'VOIP',
    X: 'Http/https redirect for dpi denied session',
    Y: 'no syn',
    a: 'rtp analysis',
    h: 'Https redirect error page',
    i: 'in offload flow',
    m: 'media mon',
    p: 'permanent',
    s: 'media signal',
    d: 'DPI cache hit',
    f: 'FIB init pending',
    c: 'MSCS or SCS session',
    x: 'Air Express',
    y: 'Application Performance Monitor',
};

export const DATAPATH_OFFLOAD_FLAG_NAMES: Readonly<Record<string, string>> = {
    O: 'Openflow',
    E: 'Default',
    U: 'User os unknown',
    T: 'Tunnel',
    R: 'L3 route',
};

const PROTOCOL_NAMES: Readonly<Record<string, string>> = {
    '1': 'ICMP',
    '2': 'IGMP',
    '6': 'TCP',
    '17': 'UDP',
    '0806': 'ARP',
};

const SEPARATOR_LINE_PATTERN = /^[-=\s]+$/;
const PROMPT_LINE_PATTERN = /^\S+#\s*$/;

type ColumnStarts = {
    sourceIp: number;
    destinationIp: number;
    prot: number;
    sport: number;
    dport: number;
    cntr: number;
    prio: number;
    tos: number;
    age: number;
    destination: number;
    tage: number;
    packets: number;
    bytes: number;
    flags: number;
    offloadFlags: number;
};

function sliceColumn(line: string, start: number, end: number | null): string {
    const slice = end === null ? line.slice(start) : line.slice(start, end);

    return slice.trim();
}

function findHeaderLine(lines: string[]): string | null {
    return (
        lines.find(
            (line) =>
                line.includes('Source IP') &&
                line.includes('Destination IP') &&
                line.includes('Flags') &&
                line.includes('Offload'),
        ) ?? null
    );
}

function findStandaloneAgeStart(headerLine: string, afterIndex: number): number {
    const searchFrom = Math.max(0, afterIndex);
    const ageMatch = headerLine.slice(searchFrom).match(/(?:^| )\bAge\b/);
    if (ageMatch?.index === undefined) {
        return -1;
    }

    const relativeIndex = ageMatch[0].startsWith(' ')
        ? ageMatch.index + 1
        : ageMatch.index;

    return searchFrom + relativeIndex;
}

function buildColumnStarts(headerLine: string): ColumnStarts | null {
    const sourceIp = headerLine.indexOf('Source IP');
    const destinationIp = headerLine.indexOf('Destination IP');
    const prot = headerLine.indexOf('Prot');
    const sport = headerLine.indexOf('SPort');
    const dport = headerLine.indexOf('Dport');
    const cntr = headerLine.indexOf('Cntr');
    const prio = headerLine.indexOf('Prio');
    const tos = headerLine.indexOf('ToS');
    const age = findStandaloneAgeStart(headerLine, tos === -1 ? 0 : tos + 3);
    const tage = headerLine.indexOf('TAge');
    const packets = headerLine.indexOf('Packets');
    const bytes = headerLine.indexOf('Bytes');
    const flags = headerLine.indexOf('Flags');
    const offloadFlags = headerLine.indexOf('Offload flags');

    // "Destination" (iface) appears after Age and before TAge
    let destination = -1;
    if (age !== -1 && tage !== -1) {
        const region = headerLine.slice(age, tage);
        const destRelative = region.indexOf('Destination');
        if (destRelative !== -1) {
            destination = age + destRelative;
        }
    }

    if (
        sourceIp === -1 ||
        destinationIp === -1 ||
        prot === -1 ||
        sport === -1 ||
        dport === -1 ||
        cntr === -1 ||
        prio === -1 ||
        tos === -1 ||
        age === -1 ||
        destination === -1 ||
        tage === -1 ||
        packets === -1 ||
        bytes === -1 ||
        flags === -1 ||
        offloadFlags === -1
    ) {
        return null;
    }

    return {
        sourceIp,
        destinationIp,
        prot,
        sport,
        dport,
        cntr,
        prio,
        tos,
        age,
        destination,
        tage,
        packets,
        bytes,
        flags,
        offloadFlags,
    };
}

function parseDataRow(
    line: string,
    columnStarts: ColumnStarts,
): DatapathSessionTableRow | null {
    const trimmed = line.trim();
    if (
        trimmed === '' ||
        SEPARATOR_LINE_PATTERN.test(line) ||
        PROMPT_LINE_PATTERN.test(trimmed)
    ) {
        return null;
    }

    // Skip legend / preamble lines that can appear before the header is found
    // (also skip any leftover non-data lines after header).
    if (
        trimmed.startsWith('Flags:') ||
        trimmed.startsWith('RAP Flags:') ||
        trimmed.startsWith('Flow Offload') ||
        trimmed.startsWith('Datapath Session')
    ) {
        return null;
    }

    const sourceIp = sliceColumn(
        line,
        columnStarts.sourceIp,
        columnStarts.destinationIp,
    );
    if (sourceIp === '') {
        return null;
    }

    // Flags can overflow the fixed Flags column into Offload flags.
    // Split the trailing remainder on whitespace: first token = session flags,
    // optional second = offload denylist flags.
    const flagsRemainder = sliceColumn(line, columnStarts.flags, null);
    const flagTokens = flagsRemainder.split(/\s+/).filter((token) => token !== '');
    const flags = flagTokens[0] ?? '';
    const offloadFlags = flagTokens[1] ?? '';

    return {
        sourceIp,
        destinationIp: sliceColumn(
            line,
            columnStarts.destinationIp,
            columnStarts.prot,
        ),
        prot: sliceColumn(line, columnStarts.prot, columnStarts.sport),
        sport: sliceColumn(line, columnStarts.sport, columnStarts.dport),
        dport: sliceColumn(line, columnStarts.dport, columnStarts.cntr),
        cntr: sliceColumn(line, columnStarts.cntr, columnStarts.prio),
        prio: sliceColumn(line, columnStarts.prio, columnStarts.tos),
        tos: sliceColumn(line, columnStarts.tos, columnStarts.age),
        age: sliceColumn(line, columnStarts.age, columnStarts.destination),
        destination: sliceColumn(
            line,
            columnStarts.destination,
            columnStarts.tage,
        ),
        tage: sliceColumn(line, columnStarts.tage, columnStarts.packets),
        packets: sliceColumn(line, columnStarts.packets, columnStarts.bytes),
        bytes: sliceColumn(line, columnStarts.bytes, columnStarts.flags),
        flags,
        offloadFlags,
    };
}

export function parseDatapathSessionTableOutput(
    output: string,
): DatapathSessionTableParseResult {
    const raw = output;
    const lines = output.split(/\r?\n/);

    const headerLine = findHeaderLine(lines);
    if (headerLine === null) {
        return { rows: [], raw };
    }

    const columnStarts = buildColumnStarts(headerLine);
    if (columnStarts === null) {
        return { rows: [], raw };
    }

    const headerIndex = lines.indexOf(headerLine);
    const rows: DatapathSessionTableRow[] = [];

    for (let index = headerIndex + 1; index < lines.length; index += 1) {
        const line = lines[index] ?? '';
        const row = parseDataRow(line, columnStarts);
        if (row !== null) {
            rows.push(row);
        }
    }

    return { rows, raw };
}

export function expandSessionFlags(flags: string): string[] {
    return [...flags].map(
        (flag) => DATAPATH_SESSION_FLAG_NAMES[flag] ?? flag,
    );
}

export function expandOffloadFlags(flags: string): string[] {
    return [...flags].map(
        (flag) => DATAPATH_OFFLOAD_FLAG_NAMES[flag] ?? flag,
    );
}

export function protocolDisplayName(prot: string): string {
    const trimmed = prot.trim();
    if (trimmed === '') {
        return '';
    }

    return PROTOCOL_NAMES[trimmed] ?? trimmed;
}

/**
 * Parse Aruba CLI hex counters (e.g. "23e5", "11f70") to decimal strings.
 * Empty input stays empty. Non-hex falls back to the original trimmed value.
 */
export function parseHexCounter(value: string): string {
    const trimmed = value.trim();
    if (trimmed === '') {
        return '';
    }

    if (!/^[0-9a-fA-F]+$/.test(trimmed)) {
        return trimmed;
    }

    const parsed = Number.parseInt(trimmed, 16);
    if (Number.isNaN(parsed)) {
        return trimmed;
    }

    return String(parsed);
}

export function formatProtocolLabel(prot: string): string {
    const name = protocolDisplayName(prot);
    if (name === '' || name === prot) {
        return prot;
    }

    return name;
}

function rowDisplayValues(row: DatapathSessionTableRow): {
    [K in keyof DatapathSessionTableFilters]: string;
} {
    return {
        sourceIp: row.sourceIp,
        destinationIp: row.destinationIp,
        prot: formatProtocolLabel(row.prot),
        sport: row.sport,
        dport: row.dport,
        cntr: row.cntr,
        prio: row.prio,
        tos: row.tos,
        age: row.age,
        destination: row.destination,
        tage: parseHexCounter(row.tage),
        packets: parseHexCounter(row.packets),
        bytes: parseHexCounter(row.bytes),
        flags: row.flags,
        offloadFlags: row.offloadFlags,
    };
}

function matchesSubstring(haystack: string, needle: string): boolean {
    if (needle.trim() === '') {
        return true;
    }

    return haystack.toLowerCase().includes(needle.trim().toLowerCase());
}

function matchesFlagsFilter(
    rawFlags: string,
    expand: (flags: string) => string[],
    query: string,
): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return true;
    }

    const needle = trimmed.toLowerCase();

    if (rawFlags.toLowerCase().includes(needle)) {
        return true;
    }

    return expand(rawFlags).some((name) => name.toLowerCase().includes(needle));
}

function matchesProtocolFilter(prot: string, query: string): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return true;
    }

    const needle = trimmed.toLowerCase();
    if (prot.toLowerCase().includes(needle)) {
        return true;
    }

    const name = protocolDisplayName(prot).toLowerCase();
    if (name !== '' && name.includes(needle)) {
        return true;
    }

    return false;
}

export function hasActiveDatapathSessionTableFilters(
    filters: DatapathSessionTableFilters,
): boolean {
    return (
        Object.keys(filters) as (keyof DatapathSessionTableFilters)[]
    ).some((key) => filters[key].trim() !== '');
}

export function matchesDatapathSessionTableFilters(
    row: DatapathSessionTableRow,
    filters: DatapathSessionTableFilters,
): boolean {
    const display = rowDisplayValues(row);

    if (!matchesSubstring(display.sourceIp, filters.sourceIp)) {
        return false;
    }
    if (!matchesSubstring(display.destinationIp, filters.destinationIp)) {
        return false;
    }
    if (!matchesProtocolFilter(row.prot, filters.prot)) {
        return false;
    }
    if (!matchesSubstring(display.sport, filters.sport)) {
        return false;
    }
    if (!matchesSubstring(display.dport, filters.dport)) {
        return false;
    }
    if (!matchesSubstring(display.cntr, filters.cntr)) {
        return false;
    }
    if (!matchesSubstring(display.prio, filters.prio)) {
        return false;
    }
    if (!matchesSubstring(display.tos, filters.tos)) {
        return false;
    }
    if (!matchesSubstring(display.age, filters.age)) {
        return false;
    }
    if (!matchesSubstring(display.destination, filters.destination)) {
        return false;
    }
    if (!matchesSubstring(display.tage, filters.tage)) {
        return false;
    }
    if (!matchesSubstring(display.packets, filters.packets)) {
        return false;
    }
    if (!matchesSubstring(display.bytes, filters.bytes)) {
        return false;
    }
    if (
        !matchesFlagsFilter(row.flags, expandSessionFlags, filters.flags)
    ) {
        return false;
    }
    if (
        !matchesFlagsFilter(
            row.offloadFlags,
            expandOffloadFlags,
            filters.offloadFlags,
        )
    ) {
        return false;
    }

    return true;
}

export function filterDatapathSessionTableRows(
    rows: DatapathSessionTableRow[],
    filters: DatapathSessionTableFilters,
): DatapathSessionTableRow[] {
    return rows.filter((row) =>
        matchesDatapathSessionTableFilters(row, filters),
    );
}
