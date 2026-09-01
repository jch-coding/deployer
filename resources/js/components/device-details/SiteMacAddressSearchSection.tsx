import type { ColumnDef } from '@tanstack/react-table';
import { Download, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { csrfHeaders } from '@/lib/csrf';
import {
    fetchMacAddressTableOutputForSerial,
    MAC_ADDRESS_SEARCH_MAX_SWITCHES,
    runMacAddressSearch,
    type MacAddressSearchMatch,
    type MacAddressSearchProgress,
    type MacAddressSearchSwitchError,
} from '@/lib/mac-address-search';
import { downloadMacAddressSearchCsv } from '@/lib/mac-address-search-csv';
import {
    filterMacAddressTableRows,
    isSwitchDevice,
    parseMacAddressLines,
} from '@/lib/mac-address-table';
import { deployments as deploymentsRoute } from '@/routes/device-details';
import { devices as deploymentDevicesRoute } from '@/routes/device-details/deployments';

type DeviceRow = {
    deviceName: string;
    serialNumber: string;
    deviceType: string;
    deviceFunction: string;
    model: string;
};

type DeploymentSummary = {
    id: number;
    name: string;
};

type DeploymentDevice = {
    id: number;
    name: string;
    serial: string;
    mac_address: string;
};

type SwitchScope = 'all' | 'selected';
type MacInputMode = 'manual' | 'deployment';

type SiteMacAddressSearchSectionProps = {
    devices: DeviceRow[];
    selectedSerials: string[];
    siteLabel: string;
    hasSiteSelected: boolean;
    hasSearchResults: boolean;
    onClose: () => void;
};

const resultColumns: ColumnDef<MacAddressSearchMatch>[] = [
    { accessorKey: 'deviceName', header: 'Switch' },
    {
        accessorKey: 'serial',
        header: 'Serial',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.serial}</span>
        ),
    },
    {
        accessorKey: 'mac',
        header: 'MAC Address',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.mac}</span>
        ),
    },
    { accessorKey: 'vlan', header: 'VLAN' },
    { accessorKey: 'type', header: 'Type' },
    {
        accessorKey: 'port',
        header: 'Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.port}</span>
        ),
    },
];

function toSwitchTarget(device: DeviceRow) {
    return {
        serial: device.serialNumber,
        deviceName: device.deviceName || device.serialNumber,
    };
}

export default function SiteMacAddressSearchSection({
    devices,
    selectedSerials,
    siteLabel,
    hasSiteSelected,
    hasSearchResults,
    onClose,
}: SiteMacAddressSearchSectionProps) {
    const [switchScope, setSwitchScope] = useState<SwitchScope>('all');
    const [macInputMode, setMacInputMode] = useState<MacInputMode>('manual');
    const [manualMacInput, setManualMacInput] = useState('');
    const [deployments, setDeployments] = useState<DeploymentSummary[]>([]);
    const [deploymentsLoading, setDeploymentsLoading] = useState(false);
    const [selectedDeploymentId, setSelectedDeploymentId] = useState('');
    const [deploymentDevices, setDeploymentDevices] = useState<
        DeploymentDevice[]
    >([]);
    const [deploymentDevicesLoading, setDeploymentDevicesLoading] =
        useState(false);
    const [selectedDeploymentDeviceIds, setSelectedDeploymentDeviceIds] =
        useState<Record<string, boolean>>({});
    const [searching, setSearching] = useState(false);
    const [progress, setProgress] = useState<MacAddressSearchProgress | null>(
        null,
    );
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [matches, setMatches] = useState<MacAddressSearchMatch[] | null>(
        null,
    );
    const [switchErrors, setSwitchErrors] = useState<
        MacAddressSearchSwitchError[]
    >([]);
    const [resultsFilter, setResultsFilter] = useState('');

    const siteSwitches = useMemo(
        () =>
            devices
                .filter(
                    (device) =>
                        device.serialNumber.trim() !== '' &&
                        isSwitchDevice({
                            deviceType: device.deviceType,
                            deviceFunction: device.deviceFunction,
                            model: device.model,
                        }),
                )
                .map(toSwitchTarget),
        [devices],
    );

    const selectedSwitches = useMemo(() => {
        const selected = new Set(selectedSerials);

        return devices
            .filter(
                (device) =>
                    selected.has(device.serialNumber) &&
                    isSwitchDevice({
                        deviceType: device.deviceType,
                        deviceFunction: device.deviceFunction,
                        model: device.model,
                    }),
            )
            .map(toSwitchTarget);
    }, [devices, selectedSerials]);

    const switchesToSearch = useMemo(
        () => (switchScope === 'selected' ? selectedSwitches : siteSwitches),
        [selectedSwitches, siteSwitches, switchScope],
    );

    const selectedDeploymentDevices = useMemo(
        () =>
            deploymentDevices.filter(
                (device) => selectedDeploymentDeviceIds[String(device.id)],
            ),
        [deploymentDevices, selectedDeploymentDeviceIds],
    );

    const filteredMatches = useMemo(() => {
        if (matches === null) {
            return [];
        }

        return matches.filter(
            (match) =>
                filterMacAddressTableRows(
                    [
                        {
                            mac: match.mac,
                            vlan: match.vlan,
                            type: match.type,
                            port: match.port,
                        },
                    ],
                    resultsFilter,
                ).length > 0,
        );
    }, [matches, resultsFilter]);

    const loadDeployments = useCallback(async () => {
        setDeploymentsLoading(true);

        try {
            const response = await fetch(deploymentsRoute.url(), {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
            });

            const body = (await response.json().catch(() => null)) as {
                deployments?: DeploymentSummary[];
                error?: string;
            } | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        `Failed to load deployments (HTTP ${response.status}).`,
                );
            }

            setDeployments(body?.deployments ?? []);
        } catch (error) {
            setSubmitError(
                error instanceof Error
                    ? error.message
                    : 'Failed to load deployments.',
            );
        } finally {
            setDeploymentsLoading(false);
        }
    }, []);

    const loadDeploymentDevices = useCallback(async (deploymentId: string) => {
        if (deploymentId === '') {
            setDeploymentDevices([]);
            setSelectedDeploymentDeviceIds({});

            return;
        }

        setDeploymentDevicesLoading(true);
        setSelectedDeploymentDeviceIds({});

        try {
            const response = await fetch(
                deploymentDevicesRoute.url({
                    deployment: Number(deploymentId),
                }),
                {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                },
            );

            const body = (await response.json().catch(() => null)) as {
                devices?: DeploymentDevice[];
                error?: string;
            } | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        `Failed to load deployment devices (HTTP ${response.status}).`,
                );
            }

            setDeploymentDevices(body?.devices ?? []);
        } catch (error) {
            setDeploymentDevices([]);
            setSubmitError(
                error instanceof Error
                    ? error.message
                    : 'Failed to load deployment devices.',
            );
        } finally {
            setDeploymentDevicesLoading(false);
        }
    }, []);

    useEffect(() => {
        if (macInputMode !== 'deployment' || deployments.length > 0) {
            return;
        }

        void loadDeployments();
    }, [deployments.length, loadDeployments, macInputMode]);

    useEffect(() => {
        if (macInputMode !== 'deployment') {
            return;
        }

        void loadDeploymentDevices(selectedDeploymentId);
    }, [loadDeploymentDevices, macInputMode, selectedDeploymentId]);

    const resolveTargetMacs = useCallback((): string[] => {
        if (macInputMode === 'deployment') {
            return selectedDeploymentDevices.map(
                (device) => device.mac_address,
            );
        }

        const parsed = parseMacAddressLines(manualMacInput);
        if (parsed.invalidLines.length > 0) {
            throw new Error(
                `Invalid MAC address${parsed.invalidLines.length === 1 ? '' : 'es'}: ${parsed.invalidLines.join(', ')}`,
            );
        }

        if (parsed.macs.length === 0) {
            throw new Error('Enter at least one MAC address.');
        }

        return parsed.macs;
    }, [macInputMode, manualMacInput, selectedDeploymentDevices]);

    const runSearch = useCallback(async () => {
        if (!hasSiteSelected) {
            setSubmitError('Select a site before searching MAC addresses.');

            return;
        }

        if (!hasSearchResults) {
            setSubmitError('Run Search first to load devices for this site.');

            return;
        }

        if (switchesToSearch.length === 0) {
            setSubmitError(
                switchScope === 'selected'
                    ? 'Select at least one switch.'
                    : 'No switches found in the current search results.',
            );

            return;
        }

        if (switchesToSearch.length > MAC_ADDRESS_SEARCH_MAX_SWITCHES) {
            setSubmitError(
                `Too many switches selected. Maximum allowed is ${MAC_ADDRESS_SEARCH_MAX_SWITCHES}.`,
            );

            return;
        }

        let targetMacs: string[];
        try {
            targetMacs = resolveTargetMacs();
        } catch (error) {
            setSubmitError(
                error instanceof Error
                    ? error.message
                    : 'Invalid MAC addresses.',
            );

            return;
        }

        setSearching(true);
        setSubmitError(null);
        setProgress(null);
        setMatches(null);
        setSwitchErrors([]);

        try {
            const result = await runMacAddressSearch({
                switches: switchesToSearch,
                targetMacs,
                fetchMacAddressTableOutput: fetchMacAddressTableOutputForSerial,
                onProgress: setProgress,
            });

            setMatches(result.matches);
            setSwitchErrors(result.errors);
        } catch (error) {
            setSubmitError(
                error instanceof Error
                    ? error.message
                    : 'Failed to search MAC addresses.',
            );
        } finally {
            setSearching(false);
            setProgress(null);
        }
    }, [
        hasSearchResults,
        hasSiteSelected,
        resolveTargetMacs,
        switchScope,
        switchesToSearch,
    ]);

    if (!hasSiteSelected) {
        return null;
    }

    return (
        <section
            className="mt-6"
            data-test="device-details-site-mac-search-section"
        >
            <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="text-lg font-medium">Site MAC search</h2>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    data-test="device-details-site-mac-search-close"
                >
                    Close
                </Button>
            </div>

            <div className="space-y-4 rounded-md border border-border p-4">
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        Switch scope
                    </legend>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="site-mac-search-switch-scope"
                            value="all"
                            checked={switchScope === 'all'}
                            onChange={() => setSwitchScope('all')}
                            data-test="device-details-site-mac-search-scope-all"
                        />
                        All switches in search results ({siteSwitches.length})
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="site-mac-search-switch-scope"
                            value="selected"
                            checked={switchScope === 'selected'}
                            onChange={() => setSwitchScope('selected')}
                            data-test="device-details-site-mac-search-scope-selected"
                        />
                        Selected switches only ({selectedSwitches.length})
                    </label>
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        MAC addresses to find
                    </legend>
                    <div className="flex flex-wrap gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="site-mac-search-mac-input"
                                value="manual"
                                checked={macInputMode === 'manual'}
                                onChange={() => setMacInputMode('manual')}
                                data-test="device-details-site-mac-search-mac-manual"
                            />
                            Manual list
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="site-mac-search-mac-input"
                                value="deployment"
                                checked={macInputMode === 'deployment'}
                                onChange={() => setMacInputMode('deployment')}
                                data-test="device-details-site-mac-search-mac-deployment"
                            />
                            From deployment
                        </label>
                    </div>

                    {macInputMode === 'manual' ? (
                        <textarea
                            value={manualMacInput}
                            onChange={(event) =>
                                setManualMacInput(event.target.value)
                            }
                            rows={4}
                            placeholder={'88:22:5b:7d:ff:00\n88-22-5b-7d-ff-80'}
                            className="flex min-h-[6rem] w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground"
                            data-test="device-details-site-mac-search-manual-input"
                        />
                    ) : (
                        <div className="space-y-3">
                            <div className="space-y-2">
                                <Label htmlFor="site-mac-search-deployment">
                                    Deployment
                                </Label>
                                <select
                                    id="site-mac-search-deployment"
                                    value={selectedDeploymentId}
                                    onChange={(event) =>
                                        setSelectedDeploymentId(
                                            event.target.value,
                                        )
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                    disabled={deploymentsLoading}
                                    data-test="device-details-site-mac-search-deployment"
                                >
                                    <option value="">Select deployment</option>
                                    {deployments.map((deployment) => (
                                        <option
                                            key={deployment.id}
                                            value={String(deployment.id)}
                                        >
                                            {deployment.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {deploymentDevicesLoading ? (
                                <p className="text-sm text-muted-foreground">
                                    Loading deployment devices…
                                </p>
                            ) : deploymentDevices.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {selectedDeploymentId === ''
                                        ? 'Select a deployment to choose devices.'
                                        : 'No devices with MAC addresses in this deployment.'}
                                </p>
                            ) : (
                                <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border border-border p-3">
                                    {deploymentDevices.map((device) => (
                                        <label
                                            key={device.id}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={
                                                    selectedDeploymentDeviceIds[
                                                        String(device.id)
                                                    ] ?? false
                                                }
                                                onCheckedChange={(checked) =>
                                                    setSelectedDeploymentDeviceIds(
                                                        (current) => ({
                                                            ...current,
                                                            [String(device.id)]:
                                                                Boolean(
                                                                    checked,
                                                                ),
                                                        }),
                                                    )
                                                }
                                                data-test={`device-details-site-mac-search-device-${device.id}`}
                                            />
                                            <span>
                                                {device.name} ({device.serial})
                                                <span className="mt-0.5 block font-mono text-xs text-muted-foreground">
                                                    {device.mac_address}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </fieldset>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        className="gap-2"
                        disabled={searching || !hasSiteSelected}
                        onClick={() => void runSearch()}
                        data-test="device-details-site-mac-search-run"
                    >
                        {searching ? (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden
                            />
                        ) : null}
                        Search MAC addresses
                    </Button>
                    {searching && progress ? (
                        <span
                            className="text-sm text-muted-foreground"
                            data-test="device-details-site-mac-search-progress"
                        >
                            Searching switch {progress.current} of{' '}
                            {progress.total} ({progress.serial})
                        </span>
                    ) : null}
                </div>

                {submitError ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-site-mac-search-error"
                    >
                        {submitError}
                    </p>
                ) : null}

                {switchErrors.length > 0 ? (
                    <div
                        className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                        data-test="device-details-site-mac-search-switch-errors"
                    >
                        <p className="font-medium">Some switches failed:</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {switchErrors.map((error) => (
                                <li key={error.serial}>
                                    {error.deviceName} ({error.serial}):{' '}
                                    {error.error}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {matches !== null ? (
                    <div className="space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-site-mac-search-count"
                            >
                                {resultsFilter.trim() !== '' &&
                                filteredMatches.length !== matches.length
                                    ? `Showing ${filteredMatches.length} of ${matches.length} matches`
                                    : `${matches.length} match${matches.length === 1 ? '' : 'es'}`}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                className="gap-2"
                                disabled={matches.length === 0}
                                onClick={() =>
                                    downloadMacAddressSearchCsv(
                                        matches,
                                        siteLabel,
                                    )
                                }
                                data-test="device-details-site-mac-search-export-csv"
                            >
                                <Download className="size-4" aria-hidden />
                                Export CSV
                            </Button>
                        </div>

                        <Input
                            value={resultsFilter}
                            onChange={(event) =>
                                setResultsFilter(event.target.value)
                            }
                            placeholder="Filter results by MAC, VLAN, type, or port"
                            data-test="device-details-site-mac-search-results-filter"
                        />

                        {matches.length === 0 ? (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-site-mac-search-empty"
                            >
                                No matching MAC addresses found on the selected
                                switches.
                            </p>
                        ) : filteredMatches.length === 0 ? (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-site-mac-search-no-filter-matches"
                            >
                                No MAC addresses match.
                            </p>
                        ) : (
                            <div
                                className="max-h-[28rem] overflow-auto rounded-md border border-border"
                                data-test="device-details-site-mac-search-results"
                            >
                                <DataTable
                                    columns={resultColumns}
                                    data={filteredMatches}
                                    getRowId={(row) =>
                                        `${row.serial}-${row.mac}-${row.port}`
                                    }
                                />
                            </div>
                        )}
                    </div>
                ) : null}
            </div>
        </section>
    );
}
