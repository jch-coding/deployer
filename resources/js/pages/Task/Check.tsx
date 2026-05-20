import { Link, usePage } from '@inertiajs/react';
import ConfigurationDiff from '@/components/ui/ConfigurationDiff';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { check as checkTask, index as taskIndex, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';

type DiffEntry = {
    path: string;
    expected: unknown;
    actual: unknown;
};

type InterfaceResult = {
    device_interface_id: number;
    device_name: string;
    interface: string;
    ok: boolean;
    missing_in_central: boolean;
    diff: DiffEntry[];
    details?: DiffEntry[];
};

type DeviceError = {
    device_id: number;
    device_name: string;
    message: string;
};

type CheckKind = 'lag' | 'ethernet';

const checkKindLabels: Record<
    CheckKind,
    { title: string; breadcrumb: string; section: string; empty: string }
> = {
    lag: {
        title: 'Verify LAG in Central',
        breadcrumb: 'Verify LAG in Central',
        section: 'LAG interfaces',
        empty: 'No LAG interfaces are attached to this task.',
    },
    ethernet: {
        title: 'Verify ethernet in Central',
        breadcrumb: 'Verify ethernet in Central',
        section: 'Ethernet interfaces',
        empty: 'No ethernet interfaces are attached to this task.',
    },
};

type CheckPageProps = SharedData & {
    task: { id: number; task_type: string; status: string };
    task_friendly_name: string;
    check_kind: CheckKind;
    deployment: { id: number; name: string };
    device_errors: DeviceError[];
    results: InterfaceResult[];
    summary: { total: number; passed: number; failed: number };
};

export default function Check() {
    const {
        current_client,
        task,
        task_friendly_name,
        check_kind,
        deployment,
        device_errors,
        results,
        summary,
    } = usePage<CheckPageProps>().props;

    const labels = checkKindLabels[check_kind];

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
            title: 'Task',
            href: showTask(task.id).url,
        },
        {
            title: labels.breadcrumb,
            href: checkTask(task.id).url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-6">
                <div className="text-center">
                    <h1 className="text-2xl font-bold">{labels.title}</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {task_friendly_name}
                    </p>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">Summary</h2>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-6 text-sm">
                        <span>
                            <span className="text-muted-foreground">Total:</span>{' '}
                            <span className="font-semibold">{summary.total}</span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">Passed:</span>{' '}
                            <span className="font-semibold text-emerald-600">
                                {summary.passed}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">Failed:</span>{' '}
                            <span className="font-semibold text-red-600">
                                {summary.failed}
                            </span>
                        </span>
                    </CardContent>
                </Card>

                {device_errors.length > 0 && (
                    <Card className="border-red-200">
                        <CardHeader className="pb-2">
                            <h2 className="text-lg font-semibold text-red-700">
                                Device errors
                            </h2>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {device_errors.map((error) => (
                                <p key={error.device_id} className="text-red-700">
                                    <span className="font-medium">{error.device_name}:</span>{' '}
                                    {error.message}
                                </p>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-lg font-semibold">{labels.section}</h2>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {results.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{labels.empty}</p>
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

                <div className="flex justify-center gap-3">
                    <Button variant="outline" asChild>
                        <Link href={showTask(task.id).url}>Back to task</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={taskIndex().url}>All tasks</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
