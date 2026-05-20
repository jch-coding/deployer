import { Link, usePage } from '@inertiajs/react';
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

type CriticalCheckPageProps = SharedData & {
    deployment: { id: number; name: string };
    lag_device_errors: DeviceError[];
    vlan_device_errors: DeviceError[];
    lag_results: InterfaceResult[];
    vlan_results: InterfaceResult[];
    static_routes: StaticRouteDevice[];
    dns_scope_id: string | null;
    dns_scope_error: string | null;
    dns_results: DnsDevice[];
    summary: {
        lag_total: number;
        lag_passed: number;
        lag_failed: number;
        vlan_total: number;
        vlan_passed: number;
        vlan_failed: number;
    };
};

function InterfaceResults({
    title,
    deviceErrors,
    results,
}: {
    title: string;
    deviceErrors: DeviceError[];
    results: InterfaceResult[];
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
                        No interfaces to check in this deployment.
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
                            {!result.ok && <ConfigurationDiff diff={result.diff} />}
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

export default function CriticalCheck() {
    const {
        current_client,
        deployment,
        lag_device_errors,
        vlan_device_errors,
        lag_results,
        vlan_results,
        static_routes,
        dns_scope_id,
        dns_scope_error,
        dns_results,
        summary,
    } = usePage<CriticalCheckPageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = [
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
    ];

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
                        <h2 className="text-lg font-semibold">Summary</h2>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-6 text-sm">
                        <span>
                            <span className="text-muted-foreground">LAG passed:</span>{' '}
                            <span className="font-semibold text-emerald-600">
                                {summary.lag_passed}/{summary.lag_total}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">LAG failed:</span>{' '}
                            <span className="font-semibold text-red-600">
                                {summary.lag_failed}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">VLAN passed:</span>{' '}
                            <span className="font-semibold text-emerald-600">
                                {summary.vlan_passed}/{summary.vlan_total}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">VLAN failed:</span>{' '}
                            <span className="font-semibold text-red-600">
                                {summary.vlan_failed}
                            </span>
                        </span>
                    </CardContent>
                </Card>

                <InterfaceResults
                    title="LAG interfaces"
                    deviceErrors={lag_device_errors}
                    results={lag_results}
                />

                <InterfaceResults
                    title="VLAN interfaces"
                    deviceErrors={vlan_device_errors}
                    results={vlan_results}
                />

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Static routes (Central)</h2>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {static_routes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No devices in deployment.</p>
                        ) : (
                            static_routes.map((device) => (
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
                        {dns_scope_id && (
                            <p className="text-muted-foreground text-xs">
                                Scope ID: {dns_scope_id}
                            </p>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {dns_scope_error && (
                            <p className="text-sm text-red-700">{dns_scope_error}</p>
                        )}
                        {!dns_scope_error &&
                            dns_results.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    No devices in deployment.
                                </p>
                            )}
                        {!dns_scope_error &&
                            dns_results.map((device) => (
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
                                                                <li key={`${profile.name}-${resolver.vrf}-${idx}`}>
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
