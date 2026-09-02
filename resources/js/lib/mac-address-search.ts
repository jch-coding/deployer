import { csrfHeaders } from '@/lib/csrf';
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import {
    getMacAddressTableCacheEntry,
    hasMacAddressTableCacheEntry,
    setMacAddressTableCacheEntry,
    type MacAddressTableCacheEntry,
} from '@/lib/mac-address-table-cache';
import {
    matchesAnyTargetMac,
    parseMacAddressTableOutput,
    type MacAddressTableParseResult,
    type MacAddressTableRow,
} from '@/lib/mac-address-table';
import { showCommands as showCommandsRoute } from '@/routes/device-details';
import { result as showCommandsResultRoute } from '@/routes/device-details/show-commands';

/** Concurrent Central fetches per batch (not a hard search limit). */
export const MAC_ADDRESS_SEARCH_BATCH_SIZE = 25;

export const MAC_ADDRESS_TABLE_COMMAND = 'show mac-address-table';

export type MacAddressSearchSwitch = {
    serial: string;
    deviceName: string;
};

export type MacAddressSearchMatch = {
    serial: string;
    deviceName: string;
    mac: string;
    vlan: string;
    type: string;
    port: string;
};

export type MacAddressSearchSwitchError = {
    serial: string;
    deviceName: string;
    error: string;
};

export type MacAddressSearchProgress = {
    /** Current batch number (1-based), or 0 when only using cache. */
    current: number;
    /** Total batches to fetch; 0 when nothing to fetch. */
    total: number;
    serial: string;
    deviceName: string;
    cachedCount: number;
    fetchCount: number;
    batchSize: number;
};

export type MacAddressSearchResult = {
    matches: MacAddressSearchMatch[];
    errors: MacAddressSearchSwitchError[];
};

export type FetchMacAddressTableOutput = (serial: string) => Promise<string>;

type PollProgressHandler = (progressPercent: number | undefined) => void;

export async function fetchMacAddressTableOutputForSerial(
    serial: string,
    options?: {
        onProgress?: PollProgressHandler;
    },
): Promise<string> {
    const response = await fetch(showCommandsRoute.url(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            serial,
            commands: [MAC_ADDRESS_TABLE_COMMAND],
        }),
    });

    const body = (await response.json().catch(() => null)) as {
        task_id?: string;
        error?: string;
    } | null;

    if (!response.ok) {
        throw new Error(
            body?.error ??
                `Failed to start show mac-address-table (HTTP ${response.status}).`,
        );
    }

    const taskId = body?.task_id?.trim();
    if (!taskId) {
        throw new Error('Central did not return a task id.');
    }

    const pollBody = await pollCxAsyncOperation(
        showCommandsResultRoute.url({ serial, taskId }),
        {
            onProgress: (progressBody) => {
                options?.onProgress?.(progressBody.progressPercent);
            },
        },
    );

    return (
        pollBody.output?.results?.find(
            (result) => result.command === MAC_ADDRESS_TABLE_COMMAND,
        )?.output ??
        pollBody.output?.results?.[0]?.output ??
        ''
    );
}

export async function getCachedOrFetchMacAddressTable(
    serial: string,
    options?: {
        force?: boolean;
        deviceName?: string;
        onProgress?: PollProgressHandler;
        fetchMacAddressTableOutput?: FetchMacAddressTableOutput;
    },
): Promise<MacAddressTableCacheEntry> {
    const trimmed = serial.trim();
    const force = options?.force === true;

    if (!force) {
        const cached = getMacAddressTableCacheEntry(trimmed);
        if (cached !== undefined) {
            return cached;
        }
    }

    const fetchOutput =
        options?.fetchMacAddressTableOutput ??
        ((serialToFetch: string) =>
            fetchMacAddressTableOutputForSerial(serialToFetch, {
                onProgress: options?.onProgress,
            }));

    const output = await fetchOutput(trimmed);
    const table = parseMacAddressTableOutput(output);
    const entry: MacAddressTableCacheEntry = {
        deviceName: options?.deviceName ?? trimmed,
        table,
    };

    setMacAddressTableCacheEntry(trimmed, entry);

    return entry;
}

function chunkArray<T>(items: T[], size: number): T[][] {
    if (size <= 0) {
        return [items];
    }

    const chunks: T[][] = [];
    for (let index = 0; index < items.length; index += size) {
        chunks.push(items.slice(index, index + size));
    }

    return chunks;
}

function matchesFromTable(
    switchDevice: MacAddressSearchSwitch,
    table: MacAddressTableParseResult,
    targetMacs: string[],
): MacAddressSearchMatch[] {
    return table.rows
        .filter((row) => matchesAnyTargetMac(row, targetMacs))
        .map((row) => toSearchMatch(switchDevice, row));
}

export async function runMacAddressSearch({
    switches,
    targetMacs,
    fetchMacAddressTableOutput,
    onProgress,
    batchSize = MAC_ADDRESS_SEARCH_BATCH_SIZE,
}: {
    switches: MacAddressSearchSwitch[];
    targetMacs: string[];
    fetchMacAddressTableOutput?: FetchMacAddressTableOutput;
    onProgress?: (progress: MacAddressSearchProgress) => void;
    batchSize?: number;
}): Promise<MacAddressSearchResult> {
    const matches: MacAddressSearchMatch[] = [];
    const errors: MacAddressSearchSwitchError[] = [];

    const cachedSwitches: MacAddressSearchSwitch[] = [];
    const uncachedSwitches: MacAddressSearchSwitch[] = [];

    for (const switchDevice of switches) {
        if (hasMacAddressTableCacheEntry(switchDevice.serial)) {
            cachedSwitches.push(switchDevice);
        } else {
            uncachedSwitches.push(switchDevice);
        }
    }

    const batches = chunkArray(uncachedSwitches, batchSize);
    const fetchCount = uncachedSwitches.length;
    const cachedCount = cachedSwitches.length;

    if (batches.length === 0) {
        onProgress?.({
            current: 0,
            total: 0,
            serial: '',
            deviceName: '',
            cachedCount,
            fetchCount: 0,
            batchSize: 0,
        });
    }

    for (let batchIndex = 0; batchIndex < batches.length; batchIndex += 1) {
        const batch = batches[batchIndex] ?? [];
        const first = batch[0];

        onProgress?.({
            current: batchIndex + 1,
            total: batches.length,
            serial: first?.serial ?? '',
            deviceName: first?.deviceName ?? '',
            cachedCount,
            fetchCount,
            batchSize: batch.length,
        });

        const settled = await Promise.allSettled(
            batch.map(async (switchDevice) => {
                const entry = await getCachedOrFetchMacAddressTable(
                    switchDevice.serial,
                    {
                        deviceName: switchDevice.deviceName,
                        fetchMacAddressTableOutput,
                    },
                );

                return { switchDevice, entry };
            }),
        );

        for (let index = 0; index < settled.length; index += 1) {
            const result = settled[index];
            const switchDevice = batch[index];
            if (result === undefined || switchDevice === undefined) {
                continue;
            }

            if (result.status === 'fulfilled') {
                continue;
            }

            errors.push({
                serial: switchDevice.serial,
                deviceName: switchDevice.deviceName,
                error:
                    result.reason instanceof Error
                        ? result.reason.message
                        : 'Failed to load MAC address table.',
            });
        }
    }

    for (const switchDevice of switches) {
        const cached = getMacAddressTableCacheEntry(switchDevice.serial);
        if (cached === undefined) {
            continue;
        }

        matches.push(
            ...matchesFromTable(switchDevice, cached.table, targetMacs),
        );
    }

    return { matches, errors };
}

function toSearchMatch(
    switchDevice: MacAddressSearchSwitch,
    row: MacAddressTableRow,
): MacAddressSearchMatch {
    return {
        serial: switchDevice.serial,
        deviceName: switchDevice.deviceName,
        mac: row.mac,
        vlan: row.vlan,
        type: row.type,
        port: row.port,
    };
}
