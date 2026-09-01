import { csrfHeaders } from '@/lib/csrf';
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import {
    matchesAnyTargetMac,
    parseMacAddressTableOutput,
    type MacAddressTableRow,
} from '@/lib/mac-address-table';
import { showCommands as showCommandsRoute } from '@/routes/device-details';
import { result as showCommandsResultRoute } from '@/routes/device-details/show-commands';

export const MAC_ADDRESS_SEARCH_MAX_SWITCHES = 25;
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
    current: number;
    total: number;
    serial: string;
    deviceName: string;
};

export type MacAddressSearchResult = {
    matches: MacAddressSearchMatch[];
    errors: MacAddressSearchSwitchError[];
};

export type FetchMacAddressTableOutput = (serial: string) => Promise<string>;

export async function fetchMacAddressTableOutputForSerial(
    serial: string,
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
    );

    return (
        pollBody.output?.results?.find(
            (result) => result.command === MAC_ADDRESS_TABLE_COMMAND,
        )?.output ??
        pollBody.output?.results?.[0]?.output ??
        ''
    );
}

export async function runMacAddressSearch({
    switches,
    targetMacs,
    fetchMacAddressTableOutput,
    onProgress,
}: {
    switches: MacAddressSearchSwitch[];
    targetMacs: string[];
    fetchMacAddressTableOutput: FetchMacAddressTableOutput;
    onProgress?: (progress: MacAddressSearchProgress) => void;
}): Promise<MacAddressSearchResult> {
    const matches: MacAddressSearchMatch[] = [];
    const errors: MacAddressSearchSwitchError[] = [];

    for (let index = 0; index < switches.length; index += 1) {
        const switchDevice = switches[index];
        if (switchDevice === undefined) {
            continue;
        }

        onProgress?.({
            current: index + 1,
            total: switches.length,
            serial: switchDevice.serial,
            deviceName: switchDevice.deviceName,
        });

        try {
            const output = await fetchMacAddressTableOutput(
                switchDevice.serial,
            );
            const parsed = parseMacAddressTableOutput(output);
            const matchedRows = parsed.rows.filter((row) =>
                matchesAnyTargetMac(row, targetMacs),
            );

            matches.push(
                ...matchedRows.map((row) => toSearchMatch(switchDevice, row)),
            );
        } catch (error) {
            errors.push({
                serial: switchDevice.serial,
                deviceName: switchDevice.deviceName,
                error:
                    error instanceof Error
                        ? error.message
                        : 'Failed to load MAC address table.',
            });
        }
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
