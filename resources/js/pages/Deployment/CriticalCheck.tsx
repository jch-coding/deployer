import { Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ConfigurationDiff, {
    type DiffEntry,
} from '@/components/ui/ConfigurationDiff';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
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
    vlan_total: number;
    vlan_passed: number;
    vlan_failed: number;
};

type CheckResults = {
    lag_device_errors: DeviceError[];
    vlan_device_errors: DeviceError[];
    lag_results: InterfaceResult[];
    vlan_results: InterfaceResult[];
    static_routes: StaticRouteDevice[];
    dns_scope_id: string | null;
    dns_scope_error: string | null;
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
    total_steps: number;
} & CheckResults;

const emptySummary: Summary = {
    lag_total: 0,
    lag_passed: 0,
    lag_failed: 0,
    vlan_total: 0,
    vlan_passed: 0,
    vlan_failed: 0,
};

function mergeCheckResults(
    current: CheckResults,
    partial: Partial<CheckResults>,
): CheckResults {
    const merged: CheckResults = {
        lag_device_errors: [
            ...current.lag_device_errors,
            ...(partial.lag_device_errors ?? []),
        ],
        vlan_device_errors: [
            ...current.vlan_device_errors,
            ...(partial.vlan_device_errors ?? []),
        ],
        lag_results: [...current.lag_results, ...(partial.lag_results ?? [])],
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
        summary: current.summary,
    };

    merged.summary = {
        lag_total: merged.lag_results.length,
        lag_passed: merged.lag_results.filter((r) => r.ok).length,
        lag_failed: merged.lag_results.filter((r) => !r.ok).length,
        vlan_total: merged.vlan_results.length,
        vlan_passed: merged.vlan_results.filter((r) => r.ok).length,
        vlan_failed: merged.vlan_results.filter((r) => !r.ok).length,
    };

    return merged;
}

function InterfaceResults({
    title,
    deviceErrors,
    results,
    pending,
}: {
    title: string;
    deviceErrors: DeviceError[];
    results: InterfaceResult[];
    pending: boolean;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <h2 className="text-lg font-semibold">{title}</h2>
            </CardHeader>
            <CardContent className="space-y-3">
                {deviceErrors.length > 0 && (
                    <div className="space-y-2 text-sm text-red-700">
                        {deviceErrors.map((error) => (
                            <p key={error.device_id}>
                                <span className="font-medium">{error.device_name}:</span>{' '}
                                {error.message}
                            </p>
                        ))}
                    </div>
                )}
                {results.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {pending
                            ? 'Waiting to check...'
                            : 'No interfaces to check in this deployment.'}
                    </p>
                ) : (
                    results.map((result) => (
                        <div
                            key={result.device_interface_id}
                            className={cn(
                                'rounded-md border px-4 py-3',
                                result.ok
                                    ? 'border-emerald-200 bg-emerald-50/50'
                                    : 'border-red-200 bg-red-50/50',
                            )}
                        >
                            <div
                                className={cn(
                                    'font-medium',
                                    result.ok ? 'text-emerald-800' : 'text-red-800',
                                )}
                            >
                                {result.interface}
                                <span className="text-muted-foreground font-normal">
                                    {' '}
                                    on {result.device_name}
                                </span>
                                {result.missing_in_central && (
                                    <span className="ml-2 text-xs font-normal text-red-600">
                                        (not found in Central)
                                    </span>
                                )}
                            </div>
                            <ConfigurationDiff
                                details={result.details ?? result.diff}
                                ok={result.ok}
                                diff={result.diff}
                            />
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

export default function CriticalCheck() {
    const { current_client, deployment, total_steps, ...initialResults } =
        usePage<CriticalCheckPageProps>().props;

    const [results, setResults] = useState<CheckResults>(() => ({
        lag_device_errors: initialResults.lag_device_errors ?? [],
        vlan_device_errors: initialResults.vlan_device_errors ?? [],
        lag_results: initialResults.lag_results ?? [],
        vlan_results: initialResults.vlan_results ?? [],
        static_routes: initialResults.static_routes ?? [],
        dns_scope_id: initialResults.dns_scope_id ?? null,
        dns_scope_error: initialResults.dns_scope_error ?? null,
        dns_results: initialResults.dns_results ?? [],
        summary: initialResults.summary ?? emptySummary,
    }));

    const [progress, setProgress] = useState<StepProgress>({
        current: 0,
        total: total_steps,
        percent: 0,
        message: 'Starting critical configuration check...',
    });
    const [checkState, setCheckState] = useState<'running' | 'complete' | 'error'>(
        'running',
    );
    const [runError, setRunError] = useState<string | null>(null);
    const checkStarted = useRef(false);

    const runCheck = useCallback(async () => {
        let context: StepContext = {};
        let accumulated: CheckResults = {
            lag_device_errors: [],
            vlan_device_errors: [],
            lag_results: [],
            vlan_results: [],
            static_routes: [],
            dns_scope_id: null,
            dns_scope_error: null,
            dns_results: [],
            summary: emptySummary,
        };

        setCheckState('running');
        setRunError(null);

        for (let step = 0; step < total_steps; step++) {
            const params = new URLSearchParams();
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
    }, [deployment.id, total_steps]);

    useEffect(() => {
        if (checkStarted.current) {
            return;
        }
        checkStarted.current = true;

        void runCheck().catch((error: unknown) => {
            setCheckState('error');
            setRunError(
                error instanceof Error ? error.message : 'Critical check failed.',
            );
        });
    }, [runCheck]);

    const isRunning = checkState === 'running';
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
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="text-center">
                    <h1 className="text-2xl font-bold">Critical configuration check</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {deployment.name}
                    </p>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Progress</h2>
                    </CardHeader>
                    <CardContent className="space-y-3">
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
                        <p className="text-muted-foreground text-sm">
                            Step {progress.current} of {progress.total}
                            {progress.percent > 0 && ` (${progress.percent}%)`}
                        </p>
                        <p className="text-sm font-medium">{progress.message}</p>
                        {runError && (
                            <p className="text-sm text-red-700">{runError}</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Summary</h2>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-6 text-sm">
                        {isRunning && results.lag_results.length === 0 ? (
                            <span className="text-muted-foreground">
                                Results will appear as each step completes.
                            </span>
                        ) : (
                            <>
                                <span>
                                    <span className="text-muted-foreground">LAG passed:</span>{' '}
                                    <span className="font-semibold text-emerald-600">
                                        {results.summary.lag_passed}/
                                        {results.summary.lag_total}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-muted-foreground">LAG failed:</span>{' '}
                                    <span className="font-semibold text-red-600">
                                        {results.summary.lag_failed}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-muted-foreground">VLAN passed:</span>{' '}
                                    <span className="font-semibold text-emerald-600">
                                        {results.summary.vlan_passed}/
                                        {results.summary.vlan_total}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-muted-foreground">VLAN failed:</span>{' '}
                                    <span className="font-semibold text-red-600">
                                        {results.summary.vlan_failed}
                                    </span>
                                </span>
                            </>
                        )}
                    </CardContent>
                </Card>

                <InterfaceResults
                    title="LAG interfaces"
                    deviceErrors={results.lag_device_errors}
                    results={results.lag_results}
                    pending={isRunning && results.lag_results.length === 0}
                />

                <InterfaceResults
                    title="VLAN interfaces"
                    deviceErrors={results.vlan_device_errors}
                    results={results.vlan_results}
                    pending={isRunning && results.vlan_results.length === 0}
                />

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Static routes (Central)</h2>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {results.static_routes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {isRunning
                                    ? 'Waiting to fetch static routes...'
                                    : 'No devices in deployment.'}
                            </p>
                        ) : (
                            results.static_routes.map((device) => (
                                <div key={device.device_id} className="rounded-md border px-4 py-3">
                                    <p className="font-medium">{device.device_name}</p>
                                    {device.error ? (
                                        <p className="mt-1 text-sm text-red-700">{device.error}</p>
                                    ) : device.routes.length === 0 ? (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            No static route profiles returned.
                                        </p>
                                    ) : (
                                        <div className="mt-2 overflow-x-auto text-sm">
                                            <table className="w-full min-w-[20rem]">
                                                <thead>
                                                    <tr className="border-b text-left">
                                                        <th className="px-2 py-1 font-medium">
                                                            Profile
                                                        </th>
                                                        <th className="px-2 py-1 font-medium">
                                                            Prefix
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {device.routes.map((route, index) => (
                                                        <tr
                                                            key={`${route.profile_name}-${route.prefix}-${index}`}
                                                            className="border-b last:border-0"
                                                        >
                                                            <td className="px-2 py-1">
                                                                {route.profile_name}
                                                            </td>
                                                            <td className="px-2 py-1 font-mono">
                                                                {route.prefix || '—'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">DNS profiles (Central)</h2>
                        {results.dns_scope_id && (
                            <p className="text-muted-foreground text-xs">
                                Scope ID: {results.dns_scope_id}
                            </p>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {results.dns_scope_error && (
                            <p className="text-sm text-red-700">{results.dns_scope_error}</p>
                        )}
                        {!results.dns_scope_error && results.dns_results.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                {isRunning
                                    ? 'Waiting to fetch DNS profiles...'
                                    : 'No devices in deployment.'}
                            </p>
                        )}
                        {!results.dns_scope_error &&
                            results.dns_results.map((device) => (
                                <div key={device.device_id} className="rounded-md border px-4 py-3">
                                    <p className="font-medium">{device.device_name}</p>
                                    {device.error ? (
                                        <p className="mt-1 text-sm text-red-700">{device.error}</p>
                                    ) : device.profiles.length === 0 ? (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            No DNS profiles returned.
                                        </p>
                                    ) : (
                                        <div className="mt-2 space-y-3 text-sm">
                                            {device.profiles.map((profile) => (
                                                <div key={profile.name}>
                                                    <p className="font-medium">{profile.name}</p>
                                                    {profile.resolvers.length === 0 ? (
                                                        <p className="text-muted-foreground text-xs">
                                                            No resolvers.
                                                        </p>
                                                    ) : (
                                                        <ul className="mt-1 list-inside list-disc space-y-1">
                                                            {profile.resolvers.map((resolver, idx) => (
                                                                <li
                                                                    key={`${profile.name}-${resolver.vrf}-${idx}`}
                                                                >
                                                                    VRF {resolver.vrf || '—'}:{' '}
                                                                    {resolver.name_server_ips.length > 0
                                                                        ? resolver.name_server_ips.join(', ')
                                                                        : '—'}
                                                                </li>
                                                            ))}
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

                <div className="flex justify-center">
                    <Button variant="outline" asChild>
                        <Link href={showDeployment(deployment.id).url}>Back to deployment</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
