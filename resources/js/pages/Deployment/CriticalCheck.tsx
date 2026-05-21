import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronDown, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import ConfigurationDiff, {
    type DiffEntry,
} from '@/components/ui/ConfigurationDiff';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import {
    critical_check as criticalCheckDeployment,
    show as showDeployment,
} from '@/routes/deployments';
import type { BreadcrumbItem, SharedData } from '@/types';

type InterfaceResult = {
    device_interface_id: number;
    device_id: number;
    device_name: string;
    interface: string;
    ok: boolean;
    missing_in_central: boolean;
    diff: DiffEntry[];
    details: DiffEntry[];
};

type DeviceError = {
    device_id: number;
    device_name: string;
    message: string;
};

type StaticRouteRow = {
    profile_name: string;
    prefix: string;
};

type StaticRouteDevice = {
    device_id: number;
    device_name: string;
    error: string | null;
    routes: StaticRouteRow[];
    source: 'device' | 'site' | null;
    site_name: string | null;
};

type DnsResolver = {
    vrf: string;
    name_server_ips: string[];
};

type DnsProfile = {
    name: string;
    resolvers: DnsResolver[];
};

type DnsDevice = {
    device_id: number;
    device_name: string;
    error: string | null;
    profiles: DnsProfile[];
};

type Summary = {
    lag_total: number;
    lag_passed: number;
    lag_failed: number;
    ethernet_total: number;
    ethernet_passed: number;
    ethernet_failed: number;
    vlan_total: number;
    vlan_passed: number;
    vlan_failed: number;
};

type CheckResults = {
    lag_device_errors: DeviceError[];
    ethernet_device_errors: DeviceError[];
    vlan_device_errors: DeviceError[];
    lag_results: InterfaceResult[];
    ethernet_results: InterfaceResult[];
    vlan_results: InterfaceResult[];
    static_routes: StaticRouteDevice[];
    dns_scope_id: string | null;
    dns_scope_error: string | null;
    dns_site_collection_name: string;
    dns_results: DnsDevice[];
    summary: Summary;
};

type StepProgress = {
    current: number;
    total: number;
    percent: number;
    message: string;
};

type StepContext = {
    dns_scope_id?: string | null;
    dns_scope_error?: string | null;
};

type StepResponse = {
    progress: StepProgress;
    partial: Partial<CheckResults>;
    context: StepContext;
    done: boolean;
};

type CriticalCheckPageProps = SharedData & {
    deployment: { id: number; name: string };
    device_count: number;
    total_steps: number;
} & CheckResults;

const emptySummary: Summary = {
    lag_total: 0,
    lag_passed: 0,
    lag_failed: 0,
    ethernet_total: 0,
    ethernet_passed: 0,
    ethernet_failed: 0,
    vlan_total: 0,
    vlan_passed: 0,
    vlan_failed: 0,
};

function emptyCheckResults(): CheckResults {
    return {
        lag_device_errors: [],
        ethernet_device_errors: [],
        vlan_device_errors: [],
        lag_results: [],
        ethernet_results: [],
        vlan_results: [],
        static_routes: [],
        dns_scope_id: null,
        dns_scope_error: null,
        dns_site_collection_name: 'WCD',
        dns_results: [],
        summary: emptySummary,
    };
}

function mergeCheckResults(
    current: CheckResults,
    partial: Partial<CheckResults>,
): CheckResults {
    const merged: CheckResults = {
        lag_device_errors: [
            ...current.lag_device_errors,
            ...(partial.lag_device_errors ?? []),
        ],
        ethernet_device_errors: [
            ...current.ethernet_device_errors,
            ...(partial.ethernet_device_errors ?? []),
        ],
        vlan_device_errors: [
            ...current.vlan_device_errors,
            ...(partial.vlan_device_errors ?? []),
        ],
        lag_results: [...current.lag_results, ...(partial.lag_results ?? [])],
        ethernet_results: [
            ...current.ethernet_results,
            ...(partial.ethernet_results ?? []),
        ],
        vlan_results: [...current.vlan_results, ...(partial.vlan_results ?? [])],
        static_routes: [...current.static_routes, ...(partial.static_routes ?? [])],
        dns_results: [...current.dns_results, ...(partial.dns_results ?? [])],
        dns_scope_id:
            partial.dns_scope_id !== undefined
                ? partial.dns_scope_id
                : current.dns_scope_id,
        dns_scope_error:
            partial.dns_scope_error !== undefined
                ? partial.dns_scope_error
                : current.dns_scope_error,
        dns_site_collection_name:
            partial.dns_site_collection_name !== undefined
                ? partial.dns_site_collection_name
                : current.dns_site_collection_name,
        summary: current.summary,
    };

    merged.summary = {
        lag_total: merged.lag_results.length,
        lag_passed: merged.lag_results.filter((r) => r.ok).length,
        lag_failed: merged.lag_results.filter((r) => !r.ok).length,
        ethernet_total: merged.ethernet_results.length,
        ethernet_passed: merged.ethernet_results.filter((r) => r.ok).length,
        ethernet_failed: merged.ethernet_results.filter((r) => !r.ok).length,
        vlan_total: merged.vlan_results.length,
        vlan_passed: merged.vlan_results.filter((r) => r.ok).length,
        vlan_failed: merged.vlan_results.filter((r) => !r.ok).length,
    };

    return merged;
}

type InterfaceKind = 'lag' | 'ethernet' | 'vlan';

type GroupedInterfaceResult = InterfaceResult & {
    kind: InterfaceKind;
};

type DeviceInterfaceGroup = {
    device_id: number;
    device_name: string;
    errors: string[];
    interfaces: GroupedInterfaceResult[];
};

const kindLabels: Record<InterfaceKind, string> = {
    lag: 'LAG',
    ethernet: 'Ethernet',
    vlan: 'VLAN',
};

function groupInterfaceResultsByDevice(
    lagResults: InterfaceResult[],
    ethernetResults: InterfaceResult[],
    vlanResults: InterfaceResult[],
    lagErrors: DeviceError[],
    ethernetErrors: DeviceError[],
    vlanErrors: DeviceError[],
): DeviceInterfaceGroup[] {
    const groups = new Map<number, DeviceInterfaceGroup>();

    const ensureGroup = (deviceId: number, deviceName: string): DeviceInterfaceGroup => {
        const existing = groups.get(deviceId);
        if (existing) {
            if (deviceName && !existing.device_name) {
                existing.device_name = deviceName;
            }

            return existing;
        }

        const group: DeviceInterfaceGroup = {
            device_id: deviceId,
            device_name: deviceName,
            errors: [],
            interfaces: [],
        };
        groups.set(deviceId, group);

        return group;
    };

    const addResults = (results: InterfaceResult[], kind: InterfaceKind) => {
        for (const result of results) {
            const group = ensureGroup(result.device_id, result.device_name);
            group.interfaces.push({ ...result, kind });
        }
    };

    const addErrors = (errors: DeviceError[]) => {
        for (const error of errors) {
            const group = ensureGroup(error.device_id, error.device_name);
            if (!group.errors.includes(error.message)) {
                group.errors.push(error.message);
            }
        }
    };

    addResults(lagResults, 'lag');
    addResults(ethernetResults, 'ethernet');
    addResults(vlanResults, 'vlan');
    addErrors(lagErrors);
    addErrors(ethernetErrors);
    addErrors(vlanErrors);

    return [...groups.values()]
        .map((group) => ({
            ...group,
            interfaces: [...group.interfaces].sort((a, b) => {
                const kindOrder =
                    kindLabels[a.kind].localeCompare(kindLabels[b.kind]);
                if (kindOrder !== 0) {
                    return kindOrder;
                }

                return a.interface.localeCompare(b.interface);
            }),
        }))
        .sort((a, b) => a.device_name.localeCompare(b.device_name));
}

function InterfaceCheckRow({ result }: { result: GroupedInterfaceResult }) {
    const [open, setOpen] = useState(false);
    const rows = result.details?.length ? result.details : result.diff;
    const hasDetails = rows.length > 0;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex items-center gap-3 border-b border-border py-2.5 last:border-0 dark:border-white/20 dark:text-white">
                {result.ok ? (
                    <Check
                        className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                        aria-label="Match"
                    />
                ) : (
                    <X
                        className="size-5 shrink-0 text-red-600 dark:text-red-400"
                        aria-label="Mismatch"
                    />
                )}
                <div className="min-w-0 flex-1">
                    <span className="font-medium">
                        {result.interface}
                        <span className="text-muted-foreground ml-2 text-xs font-normal dark:text-white/70">
                            {kindLabels[result.kind]}
                        </span>
                    </span>
                    {result.missing_in_central && (
                        <span className="mt-0.5 block text-xs opacity-80">
                            Not found in Central
                        </span>
                    )}
                </div>
                {hasDetails && (
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 dark:text-white"
                            aria-label={
                                open ? 'Hide configuration diff' : 'Show configuration diff'
                            }
                        >
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                        </Button>
                    </CollapsibleTrigger>
                )}
            </div>
            {hasDetails && (
                <CollapsibleContent className="pb-3 pl-8">
                    <ConfigurationDiff details={rows} contentOnly />
                </CollapsibleContent>
            )}
        </Collapsible>
    );
}

function sortGroupedInterfaces(
    interfaces: GroupedInterfaceResult[],
): GroupedInterfaceResult[] {
    return [...interfaces].sort((a, b) => {
        const kindOrder = kindLabels[a.kind].localeCompare(kindLabels[b.kind]);
        if (kindOrder !== 0) {
            return kindOrder;
        }

        return a.interface.localeCompare(b.interface);
    });
}

function InterfaceStatusCard({
    title,
    results,
    emptyMessage,
}: {
    title: string;
    results: GroupedInterfaceResult[];
    emptyMessage: string;
}) {
    return (
        <Card className="dark:text-white">
            <CardHeader className="pb-2">
                <CardTitle className="text-lg dark:text-white">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {results.length === 0 ? (
                    <p className="text-muted-foreground text-sm dark:text-white/80">
                        {emptyMessage}
                    </p>
                ) : (
                    <div className="divide-y divide-border dark:divide-white/20">
                        {results.map((result) => (
                            <InterfaceCheckRow
                                key={`${result.kind}-${result.device_interface_id}`}
                                result={result}
                            />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function DeviceInterfaceCheckCard({ group }: { group: DeviceInterfaceGroup }) {
    const passed = useMemo(
        () => sortGroupedInterfaces(group.interfaces.filter((result) => result.ok)),
        [group.interfaces],
    );
    const failed = useMemo(
        () => sortGroupedInterfaces(group.interfaces.filter((result) => !result.ok)),
        [group.interfaces],
    );

    return (
        <Card className="dark:text-white">
            <CardHeader className="pb-2">
                <CardTitle className="text-lg dark:text-white">{group.device_name}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {group.errors.length > 0 && (
                    <div className="space-y-1 text-sm dark:text-white">
                        {group.errors.map((message) => (
                            <p key={message}>{message}</p>
                        ))}
                    </div>
                )}
                {group.interfaces.length === 0 ? (
                    <p className="text-muted-foreground text-sm dark:text-white/80">
                        No interfaces checked for this device.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <InterfaceStatusCard
                            title="Passed"
                            results={passed}
                            emptyMessage="No interfaces passed verification."
                        />
                        <InterfaceStatusCard
                            title="Failed"
                            results={failed}
                            emptyMessage="No interfaces failed verification."
                        />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function uniqueStaticRouteProfiles(devices: StaticRouteDevice[]): StaticRouteRow[] {
    const seen = new Set<string>();
    const unique: StaticRouteRow[] = [];

    for (const device of devices) {
        if (device.error) {
            continue;
        }
        for (const route of device.routes) {
            const key = `${route.profile_name}\0${route.prefix}`;
            if (seen.has(key)) {
                continue;
            }
            seen.add(key);
            unique.push(route);
        }
    }

    return unique.sort(
        (a, b) =>
            a.profile_name.localeCompare(b.profile_name) ||
            a.prefix.localeCompare(b.prefix),
    );
}

function staticRouteInheritanceLabel(device: StaticRouteDevice): string {
    if (device.error) {
        return device.error;
    }
    if (device.source === 'device') {
        return 'Local override';
    }
    if (device.source === 'site') {
        return device.site_name ?? 'site';
    }

    return '—';
}

function StaticRoutesCentralSection({
    devices,
    pending,
}: {
    devices: StaticRouteDevice[];
    pending: boolean;
}) {
    const uniqueProfiles = useMemo(
        () => uniqueStaticRouteProfiles(devices),
        [devices],
    );

    const sortedDevices = useMemo(
        () => [...devices].sort((a, b) => a.device_name.localeCompare(b.device_name)),
        [devices],
    );

    if (pending && devices.length === 0) {
        return (
            <p className="text-muted-foreground text-sm dark:text-white">
                Waiting to fetch static routes...
            </p>
        );
    }

    if (devices.length === 0) {
        return (
            <p className="text-muted-foreground text-sm dark:text-white">
                No devices in deployment.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            <div>
                <h3 className="mb-2 text-sm font-semibold dark:text-white">Profiles</h3>
                {uniqueProfiles.length === 0 ? (
                    <p className="text-muted-foreground text-sm dark:text-white/80">
                        No static route profiles returned.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border text-sm dark:border-white/20">
                        <table className="w-full min-w-[20rem] dark:text-white">
                            <thead>
                                <tr className="border-b bg-muted/50 text-left dark:border-white/20 dark:bg-white/10">
                                    <th className="px-3 py-2 font-medium">Profile</th>
                                    <th className="px-3 py-2 font-medium">Prefix</th>
                                </tr>
                            </thead>
                            <tbody>
                                {uniqueProfiles.map((route) => (
                                    <tr
                                        key={`${route.profile_name}-${route.prefix}`}
                                        className="border-b last:border-0 dark:border-white/20"
                                    >
                                        <td className="px-3 py-2">{route.profile_name}</td>
                                        <td className="px-3 py-2 font-mono">
                                            {route.prefix || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div>
                <h3 className="mb-2 text-sm font-semibold dark:text-white">Devices</h3>
                <div className="overflow-x-auto rounded-md border text-sm dark:border-white/20">
                    <table className="w-full min-w-[20rem] dark:text-white">
                        <thead>
                            <tr className="border-b bg-muted/50 text-left dark:border-white/20 dark:bg-white/10">
                                <th className="px-3 py-2 font-medium">Device</th>
                                <th className="px-3 py-2 font-medium">Inherited from</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sortedDevices.map((device) => {
                                const inheritance = staticRouteInheritanceLabel(device);
                                const hasError = Boolean(device.error);

                                return (
                                    <tr
                                        key={device.device_id}
                                        className="border-b last:border-0 dark:border-white/20"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {device.device_name}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2',
                                                hasError && 'text-red-700 dark:text-red-300',
                                            )}
                                        >
                                            {inheritance}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function DeviceGroupedInterfaceResults({
    lagResults,
    ethernetResults,
    vlanResults,
    lagErrors,
    ethernetErrors,
    vlanErrors,
    pending,
}: {
    lagResults: InterfaceResult[];
    ethernetResults: InterfaceResult[];
    vlanResults: InterfaceResult[];
    lagErrors: DeviceError[];
    ethernetErrors: DeviceError[];
    vlanErrors: DeviceError[];
    pending: boolean;
}) {
    const deviceGroups = useMemo(
        () =>
            groupInterfaceResultsByDevice(
                lagResults,
                ethernetResults,
                vlanResults,
                lagErrors,
                ethernetErrors,
                vlanErrors,
            ),
        [
            lagResults,
            ethernetResults,
            vlanResults,
            lagErrors,
            ethernetErrors,
            vlanErrors,
        ],
    );

    if (pending && deviceGroups.length === 0) {
        return (
            <p className="text-muted-foreground text-sm dark:text-white">
                Waiting to check interfaces...
            </p>
        );
    }

    if (deviceGroups.length === 0) {
        return (
            <p className="text-muted-foreground text-sm dark:text-white">
                No interfaces to check in this deployment.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {deviceGroups.map((group) => (
                <DeviceInterfaceCheckCard key={group.device_id} group={group} />
            ))}
        </div>
    );
}

export default function CriticalCheck() {
    const { current_client, deployment, device_count, ...initialResults } =
        usePage<CriticalCheckPageProps>().props;

    const [includeEthernet, setIncludeEthernet] = useState(false);
    const [ranWithEthernet, setRanWithEthernet] = useState(false);
    const [results, setResults] = useState<CheckResults>(() => ({
        lag_device_errors: initialResults.lag_device_errors ?? [],
        ethernet_device_errors: initialResults.ethernet_device_errors ?? [],
        vlan_device_errors: initialResults.vlan_device_errors ?? [],
        lag_results: initialResults.lag_results ?? [],
        ethernet_results: initialResults.ethernet_results ?? [],
        vlan_results: initialResults.vlan_results ?? [],
        static_routes: initialResults.static_routes ?? [],
        dns_scope_id: initialResults.dns_scope_id ?? null,
        dns_scope_error: initialResults.dns_scope_error ?? null,
        dns_site_collection_name:
            initialResults.dns_site_collection_name ?? 'WCD',
        dns_results: initialResults.dns_results ?? [],
        summary: initialResults.summary ?? emptySummary,
    }));

    const phasesPerDevice = includeEthernet ? 5 : 4;
    const totalSteps = 1 + device_count * phasesPerDevice;

    const [progress, setProgress] = useState<StepProgress>({
        current: 0,
        total: totalSteps,
        percent: 0,
        message: 'Configure options and run the check.',
    });
    const [checkState, setCheckState] = useState<'idle' | 'running' | 'complete' | 'error'>(
        'idle',
    );
    const [runError, setRunError] = useState<string | null>(null);

    const runCheck = useCallback(async () => {
        const stepTotal = 1 + device_count * (includeEthernet ? 5 : 4);
        let context: StepContext = {};
        let accumulated = emptyCheckResults();

        setRanWithEthernet(includeEthernet);
        setCheckState('running');
        setRunError(null);
        setResults(accumulated);
        setProgress({
            current: 0,
            total: stepTotal,
            percent: 0,
            message: 'Starting critical configuration check...',
        });

        for (let step = 0; step < stepTotal; step++) {
            const params = new URLSearchParams();
            if (includeEthernet) {
                params.set('include_ethernet', '1');
            }
            if (context.dns_scope_id) {
                params.set('dns_scope_id', context.dns_scope_id);
            }
            if (context.dns_scope_error) {
                params.set('dns_scope_error', context.dns_scope_error);
            }

            const baseUrl = criticalCheckDeployment.step.url([deployment.id, step]);
            const url =
                params.toString() !== '' ? `${baseUrl}?${params.toString()}` : baseUrl;

            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                throw new Error(
                    body?.message ?? `Check failed (HTTP ${response.status}).`,
                );
            }

            const data = (await response.json()) as StepResponse;
            setProgress(data.progress);
            accumulated = mergeCheckResults(accumulated, data.partial);
            setResults(accumulated);
            context = { ...context, ...data.context };

            if (data.partial.dns_scope_id !== undefined) {
                context.dns_scope_id = data.partial.dns_scope_id;
            }
            if (data.partial.dns_scope_error !== undefined) {
                context.dns_scope_error = data.partial.dns_scope_error;
            }
        }

        setCheckState('complete');
        setProgress((prev) => ({
            ...prev,
            percent: 100,
            message: 'Critical configuration check complete.',
        }));
    }, [deployment.id, device_count, includeEthernet]);

    const isRunning = checkState === 'running';
    const hasStarted = checkState !== 'idle';
    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: current_client?.name ?? 'Clients',
                href: clientIndex().url,
            },
            {
                title: deployment.name,
                href: showDeployment(deployment.id).url,
            },
            {
                title: 'Critical configuration check',
                href: criticalCheckDeployment(deployment.id).url,
            },
        ],
        [current_client?.name, deployment.id, deployment.name],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                <div className="text-center">
                    <h1 className="text-2xl font-bold">Critical configuration check</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {deployment.name}
                    </p>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Options</h2>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="include-ethernet"
                                checked={includeEthernet}
                                disabled={isRunning}
                                onCheckedChange={(checked) =>
                                    setIncludeEthernet(checked === true)
                                }
                            />
                            <Label htmlFor="include-ethernet" className="text-sm font-normal">
                                Verify ethernet interfaces against Central
                            </Label>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {totalSteps} steps ({device_count} device
                            {device_count === 1 ? '' : 's'}
                            {includeEthernet ? ', including ethernet per device' : ''})
                        </p>
                        <Button
                            onClick={() => {
                                void runCheck().catch((error: unknown) => {
                                    setCheckState('error');
                                    setRunError(
                                        error instanceof Error
                                            ? error.message
                                            : 'Critical check failed.',
                                    );
                                });
                            }}
                            disabled={isRunning}
                        >
                            {checkState === 'idle' ? 'Run check' : 'Run again'}
                        </Button>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card className="dark:text-white">
                        <CardHeader className="pb-2">
                            <h2 className="text-lg font-semibold dark:text-white">Progress</h2>
                        </CardHeader>
                        <CardContent className="space-y-3 dark:text-white">
                            <div
                                className="bg-muted h-2 w-full overflow-hidden rounded-full"
                                role="progressbar"
                                aria-valuenow={progress.percent}
                                aria-valuemin={0}
                                aria-valuemax={100}
                            >
                                <div
                                    className="bg-primary h-full rounded-full transition-all duration-300"
                                    style={{ width: `${progress.percent}%` }}
                                />
                            </div>
                            <p className="text-muted-foreground text-sm dark:text-white">
                                Step {progress.current} of {progress.total}
                                {progress.percent > 0 && ` (${progress.percent}%)`}
                            </p>
                            <p className="text-sm font-medium dark:text-white">
                                {progress.message}
                            </p>
                            {runError && (
                                <p className="text-sm text-red-700 dark:text-white">{runError}</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="dark:text-white">
                        <CardHeader className="pb-2">
                            <h2 className="text-lg font-semibold dark:text-white">Summary</h2>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-6 text-sm dark:text-white">
                        {!hasStarted ? (
                            <span className="text-muted-foreground dark:text-white">
                                Run the check to see results.
                            </span>
                        ) : isRunning && results.lag_results.length === 0 ? (
                            <span className="text-muted-foreground dark:text-white">
                                Results will appear as each step completes.
                            </span>
                        ) : (
                            <>
                                <span>
                                    <span className="text-muted-foreground dark:text-white">
                                        LAG passed:
                                    </span>{' '}
                                    <span className="font-semibold text-emerald-600 dark:text-white">
                                        {results.summary.lag_passed}/
                                        {results.summary.lag_total}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-muted-foreground dark:text-white">
                                        LAG failed:
                                    </span>{' '}
                                    <span className="font-semibold text-red-600 dark:text-white">
                                        {results.summary.lag_failed}
                                    </span>
                                </span>
                                {ranWithEthernet && (
                                    <>
                                        <span>
                                            <span className="text-muted-foreground dark:text-white">
                                                Ethernet passed:
                                            </span>{' '}
                                            <span className="font-semibold text-emerald-600 dark:text-white">
                                                {results.summary.ethernet_passed}/
                                                {results.summary.ethernet_total}
                                            </span>
                                        </span>
                                        <span>
                                            <span className="text-muted-foreground dark:text-white">
                                                Ethernet failed:
                                            </span>{' '}
                                            <span className="font-semibold text-red-600 dark:text-white">
                                                {results.summary.ethernet_failed}
                                            </span>
                                        </span>
                                    </>
                                )}
                                <span>
                                    <span className="text-muted-foreground dark:text-white">
                                        VLAN passed:
                                    </span>{' '}
                                    <span className="font-semibold text-emerald-600 dark:text-white">
                                        {results.summary.vlan_passed}/
                                        {results.summary.vlan_total}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-muted-foreground dark:text-white">
                                        VLAN failed:
                                    </span>{' '}
                                    <span className="font-semibold text-red-600 dark:text-white">
                                        {results.summary.vlan_failed}
                                    </span>
                                </span>
                            </>
                        )}
                        </CardContent>
                    </Card>

                    {hasStarted && (
                        <div className="space-y-4 lg:col-span-2 dark:text-white">
                            <h2 className="text-lg font-semibold dark:text-white">
                                Interface verification
                            </h2>
                            <DeviceGroupedInterfaceResults
                                lagResults={results.lag_results}
                                ethernetResults={
                                    ranWithEthernet ? results.ethernet_results : []
                                }
                                vlanResults={results.vlan_results}
                                lagErrors={results.lag_device_errors}
                                ethernetErrors={
                                    ranWithEthernet ? results.ethernet_device_errors : []
                                }
                                vlanErrors={results.vlan_device_errors}
                                pending={
                                    isRunning &&
                                    results.lag_results.length === 0 &&
                                    results.vlan_results.length === 0 &&
                                    (!ranWithEthernet ||
                                        results.ethernet_results.length === 0)
                                }
                            />
                        </div>
                    )}

                    {hasStarted && (
                        <Card className="dark:text-white">
                            <CardHeader className="pb-2">
                                <h2 className="text-lg font-semibold dark:text-white">
                                    Static routes (Central)
                                </h2>
                            </CardHeader>
                            <CardContent className="dark:text-white">
                                <StaticRoutesCentralSection
                                    devices={results.static_routes}
                                    pending={
                                        isRunning && results.static_routes.length === 0
                                    }
                                />
                            </CardContent>
                        </Card>
                    )}

                    {hasStarted && (
                        <Card className="dark:text-white">
                            <CardHeader className="pb-2">
                                <h2 className="text-lg font-semibold dark:text-white">
                                    DNS profiles (Central)
                                </h2>
                                {results.dns_site_collection_name && (
                                    <p className="text-muted-foreground text-xs dark:text-white">
                                        Site Collection - {results.dns_site_collection_name}
                                    </p>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {results.dns_scope_error && (
                                    <p className="text-sm text-red-700 dark:text-white">
                                        {results.dns_scope_error}
                                    </p>
                                )}
                                {!results.dns_scope_error &&
                                    results.dns_results.length === 0 && (
                                        <p className="text-muted-foreground text-sm dark:text-white">
                                            {isRunning
                                                ? 'Waiting to fetch DNS profiles...'
                                                : 'No devices in deployment.'}
                                        </p>
                                    )}
                                {!results.dns_scope_error &&
                                    results.dns_results.map((device) => (
                                        <div
                                            key={device.device_id}
                                            className="rounded-md border px-4 py-3"
                                        >
                                            <p className="font-medium dark:text-white">
                                                {device.device_name}
                                            </p>
                                            {device.error ? (
                                                <p className="mt-1 text-sm text-red-700 dark:text-white">
                                                    {device.error}
                                                </p>
                                            ) : device.profiles.length === 0 ? (
                                                <p className="text-muted-foreground mt-1 text-sm dark:text-white">
                                                    No DNS profiles returned.
                                                </p>
                                            ) : (
                                                <div className="mt-2 space-y-3 text-sm">
                                                    {device.profiles.map((profile) => (
                                                        <div key={profile.name}>
                                                            <p className="font-medium dark:text-white">
                                                                {profile.name}
                                                            </p>
                                                            {profile.resolvers.length === 0 ? (
                                                                <p className="text-muted-foreground text-xs dark:text-white">
                                                                    No resolvers.
                                                                </p>
                                                            ) : (
                                                                <ul className="mt-1 list-inside list-disc space-y-1">
                                                                    {profile.resolvers.map(
                                                                        (resolver, idx) => (
                                                                            <li
                                                                                key={`${profile.name}-${resolver.vrf}-${idx}`}
                                                                            >
                                                                                VRF{' '}
                                                                                {resolver.vrf ||
                                                                                    '—'}
                                                                                :{' '}
                                                                                {resolver.name_server_ips.length >
                                                                                0
                                                                                    ? resolver.name_server_ips.join(
                                                                                          ', ',
                                                                                      )
                                                                                    : '—'}
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="flex justify-center">
                    <Button variant="outline" asChild>
                        <Link href={showDeployment(deployment.id).url}>Back to deployment</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
