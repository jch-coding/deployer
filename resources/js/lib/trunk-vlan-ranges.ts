const MIN_VLAN = 1;
const MAX_VLAN = 4094;

function expandToken(token: string, ids: number[]): void {
    const rangeMatch = /^(\d+)\s*-\s*(\d+)$/.exec(token);
    if (rangeMatch) {
        const low = parseInt(rangeMatch[1], 10);
        const high = parseInt(rangeMatch[2], 10);
        if (low > high) {
            throw new Error(`Invalid VLAN range "${token}": start must be less than or equal to end.`);
        }
        if (low < MIN_VLAN || high > MAX_VLAN) {
            throw new Error(`VLAN IDs must be between ${MIN_VLAN} and ${MAX_VLAN}.`);
        }
        for (let v = low; v <= high; v++) {
            ids.push(v);
        }

        return;
    }
    if (/^\d+$/.test(token)) {
        const v = parseInt(token, 10);
        if (v < MIN_VLAN || v > MAX_VLAN) {
            throw new Error(`VLAN IDs must be between ${MIN_VLAN} and ${MAX_VLAN}.`);
        }
        ids.push(v);

        return;
    }
    throw new Error(`Invalid trunk VLAN range token "${token}".`);
}

function collectTokens(input: string | readonly string[]): string[] {
    let joined: string;
    if (Array.isArray(input)) {
        const pieces: string[] = [];
        for (const item of input) {
            const s = String(item).trim();
            if (s !== '') {
                pieces.push(s);
            }
        }
        joined = pieces.join(',');
    } else {
        joined = String(input).trim();
    }
    if (joined === '') {
        return [];
    }
    return joined
        .split(/[,;&]+/)
        .map((t) => t.trim())
        .filter((t) => t.length > 0);
}

function collapseToCanonical(sortedUnique: number[]): string {
    const segments: string[] = [];
    let start = sortedUnique[0];
    let prev = start;
    for (let i = 1; i < sortedUnique.length; i++) {
        const current = sortedUnique[i];
        if (current === prev + 1) {
            prev = current;
            continue;
        }
        segments.push(start === prev ? String(start) : `${start}-${prev}`);
        start = current;
        prev = current;
    }
    segments.push(start === prev ? String(start) : `${start}-${prev}`);

    return segments.join('&');
}

/** Canonical storage: '&'-separated segments, each VLAN id or start-end. */
export function normalizeTrunkVlanRangesToCanonical(input: string | readonly string[] | null | undefined): string | null {
    if (input === null || input === undefined) {
        return null;
    }
    const tokens = collectTokens(input);
    if (tokens.length === 0) {
        return null;
    }
    const ids: number[] = [];
    for (const t of tokens) {
        expandToken(t, ids);
    }
    if (ids.length === 0) {
        return null;
    }
    ids.sort((a, b) => a - b);
    const unique = Array.from(new Set(ids));

    return collapseToCanonical(unique);
}

/** Comma-space separated for UI (collapses contiguous VLANs). */
export function formatTrunkVlanRangesForDisplay(value: string | readonly string[] | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }
    try {
        const canonical = Array.isArray(value)
            ? normalizeTrunkVlanRangesToCanonical(value)
            : normalizeTrunkVlanRangesToCanonical(value);
        if (canonical === null) {
            return '';
        }
        return canonical.split('&').join(', ');
    } catch {
        return Array.isArray(value) ? value.join(', ') : String(value);
    }
}

export function trunkVlanRangesInputToCanonical(input: string): string | null {
    const t = input.trim();
    if (t === '') {
        return null;
    }

    return normalizeTrunkVlanRangesToCanonical(t);
}
