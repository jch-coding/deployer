import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronDown, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import ConfigurationDiff, {
    type DiffEntry,
} from '@/components/ui/ConfigurationDiff';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { check as checkTask, index as taskIndex, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';

type InterfaceResult = {
    device_interface_id: number;
    device_id: number;
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

type DeviceCheckGroup = {
    device_id: number;
    device_name: string;
    errors: string[];
    passed: InterfaceResult[];
    failed: InterfaceResult[];
};

const checkKindLabels: Record<
    CheckKind,
    { title: string; breadcrumb: string; empty: string }
> = {
    lag: {
        title: 'Verify LAG in Central',
        breadcrumb: 'Verify LAG in Central',
        empty: 'No LAG interfaces are attached to this task.',
    },
    ethernet: {
        title: 'Verify ethernet in Central',
        breadcrumb: 'Verify ethernet in Central',
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

function groupResultsByDevice(
    results: InterfaceResult[],
    deviceErrors: DeviceError[],
): DeviceCheckGroup[] {
    const groups = new Map<number, DeviceCheckGroup>();

    const ensureGroup = (deviceId: number, deviceName: string): DeviceCheckGroup => {
        const existing = groups.get(deviceId);
        if (existing) {
            if (deviceName && !existing.device_name) {
                existing.device_name = deviceName;
            }

            return existing;
        }

        const group: DeviceCheckGroup = {
            device_id: deviceId,
            device_name: deviceName,
            errors: [],
            passed: [],
            failed: [],
        };
        groups.set(deviceId, group);

        return group;
    };

    for (const result of results) {
        const deviceId = result.device_id || 0;
        const group = ensureGroup(deviceId, result.device_name);
        if (result.ok) {
            group.passed.push(result);
        } else {
            group.failed.push(result);
        }
    }

    for (const error of deviceErrors) {
        const group = ensureGroup(error.device_id, error.device_name);
        if (!group.errors.includes(error.message)) {
            group.errors.push(error.message);
        }
    }

    const sortInterfaces = (items: InterfaceResult[]) =>
        [...items].sort((a, b) => a.interface.localeCompare(b.interface));

    return [...groups.values()]
        .map((group) => ({
            ...group,
            passed: sortInterfaces(group.passed),
            failed: sortInterfaces(group.failed),
        }))
        .sort((a, b) => a.device_name.localeCompare(b.device_name));
}

function InterfaceCheckRow({ result }: { result: InterfaceResult }) {
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
                    <span className="font-medium">{result.interface}</span>
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

function InterfaceStatusCard({
    title,
    results,
    emptyMessage,
}: {
    title: string;
    results: InterfaceResult[];
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
                                key={result.device_interface_id}
                                result={result}
                            />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function DeviceCheckSection({
    group,
    checkTitle,
}: {
    group: DeviceCheckGroup;
    checkTitle: string;
}) {
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 className="text-2xl font-bold dark:text-white">{group.device_name}</h2>
                <span className="text-muted-foreground text-lg dark:text-white/70">
                    {checkTitle}
                </span>
            </div>

            {group.errors.length > 0 && (
                <Card className="border-red-200 dark:border-red-900/50 dark:text-white">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-lg text-red-700 dark:text-red-400">
                            Device errors
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {group.errors.map((message) => (
                            <p key={message} className="text-red-700 dark:text-red-300">
                                {message}
                            </p>
                        ))}
                    </CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <InterfaceStatusCard
                    title="Passed"
                    results={group.passed}
                    emptyMessage="No interfaces passed verification."
                />
                <InterfaceStatusCard
                    title="Failed"
                    results={group.failed}
                    emptyMessage="No interfaces failed verification."
                />
            </div>
        </section>
    );
}

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

    const deviceGroups = useMemo(
        () => groupResultsByDevice(results, device_errors),
        [results, device_errors],
    );

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
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                <Card className="dark:text-white">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-lg dark:text-white">Summary</CardTitle>
                        <p className="text-muted-foreground text-sm dark:text-white/70">
                            {task_friendly_name}
                        </p>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-6 text-sm dark:text-white">
                        <span>
                            <span className="text-muted-foreground dark:text-white/70">
                                Total:
                            </span>{' '}
                            <span className="font-semibold">{summary.total}</span>
                        </span>
                        <span>
                            <span className="text-muted-foreground dark:text-white/70">
                                Passed:
                            </span>{' '}
                            <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                {summary.passed}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground dark:text-white/70">
                                Failed:
                            </span>{' '}
                            <span className="font-semibold text-red-600 dark:text-red-400">
                                {summary.failed}
                            </span>
                        </span>
                    </CardContent>
                </Card>

                {deviceGroups.length === 0 ? (
                    <p className="text-muted-foreground text-center text-sm dark:text-white/80">
                        {labels.empty}
                    </p>
                ) : (
                    deviceGroups.map((group) => (
                        <DeviceCheckSection
                            key={group.device_id}
                            group={group}
                            checkTitle={labels.title}
                        />
                    ))
                )}

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
