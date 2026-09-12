import { csrfHeaders } from '@/lib/csrf';
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import {
    getDatapathSessionTableCacheEntry,
    setDatapathSessionTableCacheEntry,
    type DatapathSessionTableCacheEntry,
} from '@/lib/datapath-session-table-cache';
import { parseDatapathSessionTableOutput } from '@/lib/datapath-session-table';
import { showCommands as showCommandsRoute } from '@/routes/device-details';
import { result as apShowCommandsResultRoute } from '@/routes/device-details/ap-show-commands';

export const DATAPATH_SESSION_TABLE_COMMAND = 'show datapath session';

type PollProgressHandler = (progressPercent: number | undefined) => void;

export type FetchDatapathSessionTableOutput = (
    serial: string,
) => Promise<string>;

export async function fetchDatapathSessionTableOutputForSerial(
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
            commands: [DATAPATH_SESSION_TABLE_COMMAND],
            device_type: 'ACCESS_POINT',
        }),
    });

    const body = (await response.json().catch(() => null)) as {
        task_id?: string;
        error?: string;
    } | null;

    if (!response.ok) {
        throw new Error(
            body?.error ??
                `Failed to start show datapath session (HTTP ${response.status}).`,
        );
    }

    const taskId = body?.task_id?.trim();
    if (!taskId) {
        throw new Error('Central did not return a task id.');
    }

    const pollBody = await pollCxAsyncOperation(
        apShowCommandsResultRoute.url({ serial, taskId }),
        {
            onProgress: (progressBody) => {
                options?.onProgress?.(progressBody.progressPercent);
            },
        },
    );

    return (
        pollBody.output?.results?.find(
            (result) => result.command === DATAPATH_SESSION_TABLE_COMMAND,
        )?.output ??
        pollBody.output?.results?.[0]?.output ??
        ''
    );
}

export async function getCachedOrFetchDatapathSessionTable(
    serial: string,
    options?: {
        force?: boolean;
        onProgress?: PollProgressHandler;
        fetchDatapathSessionTableOutput?: FetchDatapathSessionTableOutput;
    },
): Promise<DatapathSessionTableCacheEntry> {
    const trimmed = serial.trim();
    const force = options?.force === true;

    if (!force) {
        const cached = getDatapathSessionTableCacheEntry(trimmed);
        if (cached !== undefined) {
            return cached;
        }
    }

    const fetchOutput =
        options?.fetchDatapathSessionTableOutput ??
        ((serialToFetch: string) =>
            fetchDatapathSessionTableOutputForSerial(serialToFetch, {
                onProgress: options?.onProgress,
            }));

    const output = await fetchOutput(trimmed);
    const table = parseDatapathSessionTableOutput(output);
    const entry: DatapathSessionTableCacheEntry = { table };

    setDatapathSessionTableCacheEntry(trimmed, entry);

    return entry;
}
