import { Form, router, useForm, usePage } from '@inertiajs/react';
import type { RowSelectionState } from '@tanstack/react-table';
import { Crown, Download, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import type { LicenseTypeOption } from '@/lib/license-types';
import { downloadSampleDeviceCsv } from '@/lib/sample-device-csv';
import { storeMany } from '@/actions/App/Http/Controllers/DeviceController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import {
    createDeploymentShowColumns,
    type DeviceDef,
} from '@/components/ui/devices-columns';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import type { AvailableSubscription } from '@/components/licensing/LicenseSelect';
import TaskCard from '@/components/ui/TaskCard';
import TaskItemsCard from '@/components/ui/TaskItemsCard';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import {
    critical_check as criticalCheckDeployment,
    index as deploymentsIndex,
    provision as provisionDeployment,
    refreshScopeIds,
    show as showDeployment,
} from '@/routes/deployments';
import { show as showTask } from '@/routes/tasks';
import {
    groupTasksByLaunchCategory,
    TASK_LAUNCH_CATEGORIES,
    taskMatchesSearch,
    type TaskLaunchCategoryId,
    type TaskLaunchSearchable,
} from '@/lib/task-launch-categories';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

/** Matches deployment payload from DeploymentController::show (devices used by task cards). */
type DeploymentSummary = {
    id: number;
    name: string;
    devices: Array<{
        id: number;
        name: string;
        serial?: string | number;
        device_function?: string;
    }>;
};

type Task = {
    id: number;
    task_type: string;
    devices_count: number;
    status: string;
    created_at: string;
    updated_at: string;
    human_updated_at: string;
    human_created_at: string;
    friendly_name: string;
    friendly_description: string;
    required_columns: string[];
};

type LaunchTask = TaskLaunchSearchable;

function formatCsvUploadErrorMessage(message: string | string[]): string {
    return Array.isArray(message) ? message.join(' ') : message;
}

function groupCsvUploadErrors(
    errors: Record<string, string | string[]>,
): {
    headerErrors: Array<{ key: string; message: string }>;
    rowErrors: Array<{ key: string; message: string }>;
    otherErrors: Array<{ key: string; message: string }>;
} {
    const headerErrors: Array<{ key: string; message: string }> = [];
    const rowErrors: Array<{ key: string; message: string }> = [];
    const otherErrors: Array<{ key: string; message: string }> = [];

    for (const [key, message] of Object.entries(errors)) {
        const entry = {
            key,
            message: formatCsvUploadErrorMessage(message),
        };
        if (key.startsWith('CSV headers:')) {
            headerErrors.push(entry);
        } else if (key.startsWith('Row ')) {
            rowErrors.push(entry);
        } else {
            otherErrors.push(entry);
        }
    }

    rowErrors.sort((a, b) => {
        const rowA = Number.parseInt(a.key.match(/^Row (\d+)/)?.[1] ?? '0', 10);
        const rowB = Number.parseInt(b.key.match(/^Row (\d+)/)?.[1] ?? '0', 10);
        return rowA - rowB || a.key.localeCompare(b.key);
    });

    return { headerErrors, rowErrors, otherErrors };
}

type CentralScopeOption = {
    scopeName: string;
    scopeId: string;
};

type DeploymentPageProps = {
    devices: Paginator<DeviceDef>;
    device_search?: string;
    base_urls: string[];
    deployment: DeploymentSummary;
    tasks: LaunchTask[];
    latest_tasks: Task[];
    available_subscriptions: AvailableSubscription[];
    enabled_services: string[];
    license_tags: string[];
    license_type_options: string[];
    central_licensing_error: string | null;
    licensing_synced_at: string | null;
    cx_firmware_versions: string[];
    central_firmware_error: string | null;
    central_sites: CentralScopeOption[];
    central_sites_error: string | null;
    central_device_groups: CentralScopeOption[];
    central_device_groups_error: string | null;
} & SharedData;
export default function Show() {
    const {
        deployment,
        current_client,
        flash,
        device_search = '',
    } = usePage<DeploymentPageProps>().props;
    const devicesPaginator = usePage<DeploymentPageProps>().props.devices;
    const devicesFromServer = devicesPaginator.data;
    const deploymentId = Number(deployment.id);

    const [deviceTableSearch, setDeviceTableSearch] = useState(device_search);
    const [taskSearch, setTaskSearch] = useState('');
    const [selectedTaskCategoryId, setSelectedTaskCategoryId] =
        useState<TaskLaunchCategoryId | null>(null);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [allFilteredSelected, setAllFilteredSelected] = useState(false);
    const [syncingScopeIds, setSyncingScopeIds] = useState(false);
    const previousDeploymentIdRef = useRef(deploymentId);
    const previousDeviceSearchRef = useRef(device_search.trim());

    const devicesForTasks = useMemo(
        () =>
            (deployment.devices ?? []).map((d) => ({
                id: d.id,
                name: d.name,
                completed: false,
                device_function: d.device_function ?? '',
                serial: d.serial,
            })),
        [deployment.devices ?? []],
    );
    const { setData, post, progress, errors } = useForm<{
        devices: File | null;
    }>({
        devices: null,
    });
    const tasks = usePage<DeploymentPageProps>().props.tasks;
    const latest_tasks = usePage<DeploymentPageProps>().props.latest_tasks;
    const [submitting, setSubmitting] = useState(false);
    const {
        available_subscriptions = [],
        enabled_services = [],
        license_tags = [],
        license_type_options = [],
        central_licensing_error,
        licensing_synced_at = null,
        cx_firmware_versions = [],
        central_firmware_error = null,
        central_sites = [],
        central_sites_error = null,
        central_device_groups = [],
        central_device_groups_error = null,
    } = usePage<DeploymentPageProps>().props;

    const deviceTableColumns = useMemo(
        () =>
            createDeploymentShowColumns({
                centralSites: central_sites,
                centralDeviceGroups: central_device_groups,
                centralSitesError: central_sites_error,
                centralDeviceGroupsError: central_device_groups_error,
            }),
        [
            central_sites,
            central_device_groups,
            central_sites_error,
            central_device_groups_error,
        ],
    );

    const classicCentralTaskTypes = new Set([
        'ASSOCIATE_DEVICE_TO_SITE',
        'ASSOCIATE_SITE_AND_NAME',
        'PREPROVISION_DEVICE_TO_GROUP',
        'MOVE_DEVICE_TO_GROUP',
        'ADD_VLANS_TO_DEVICE_GROUP',
        'ASSIGN_SUBSCRIPTION',
        'UNASSIGN_SUBSCRIPTION',
    ]);

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.success, flash?.error]);

    useEffect(() => {
        if (previousDeploymentIdRef.current !== deploymentId) {
            previousDeploymentIdRef.current = deploymentId;
            setDeviceTableSearch(device_search);
            setRowSelection({});
            setAllFilteredSelected(false);
        }
    }, [deploymentId, device_search]);

    useEffect(() => {
        const trimmed = deviceTableSearch.trim();
        if (previousDeviceSearchRef.current !== trimmed) {
            previousDeviceSearchRef.current = trimmed;
            setRowSelection({});
            setAllFilteredSelected(false);
        }
    }, [deviceTableSearch]);

    const selectedIds = useMemo(
        () =>
            Object.entries(rowSelection)
                .filter(([, selected]) => selected)
                .map(([id]) => Number(id)),
        [rowSelection],
    );

    const selectedCount = allFilteredSelected
        ? devicesPaginator.total
        : selectedIds.length;

    const isAllFilteredSelected =
        allFilteredSelected ||
        (selectedCount > 0 && selectedCount === devicesPaginator.total);

    const allPageRowsSelected =
        devicesFromServer.length > 0 &&
        devicesFromServer.every((device) => rowSelection[String(device.id)]);

    const showSelectAllFilteredBanner =
        allPageRowsSelected &&
        !allFilteredSelected &&
        devicesPaginator.total > devicesFromServer.length;

    const handleForceSyncScopeIds = useCallback(() => {
        setSyncingScopeIds(true);
        router.post(
            refreshScopeIds.url(deploymentId),
            isAllFilteredSelected
                ? {
                      sync_all: true,
                      ...(deviceTableSearch.trim() !== ''
                          ? { search: deviceTableSearch.trim() }
                          : {}),
                  }
                : { device_ids: selectedIds },
            {
                preserveScroll: true,
                onFinish: () => setSyncingScopeIds(false),
            },
        );
    }, [
        deploymentId,
        deviceTableSearch,
        isAllFilteredSelected,
        selectedIds,
    ]);

    useEffect(() => {
        const trimmed = deviceTableSearch.trim();
        const trimmedServer = device_search.trim();
        if (trimmed === trimmedServer) {
            return;
        }
        const handle = window.setTimeout(() => {
            router.get(
                showDeployment.url(deploymentId, {
                    query: {
                        ...(trimmed !== '' ? { search: trimmed } : {}),
                    },
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['devices', 'device_search', 'latest_tasks'],
                },
            );
        }, 350);
        return () => window.clearTimeout(handle);
    }, [deploymentId, deviceTableSearch, device_search]);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Deployments',
            href: deploymentsIndex().url,
        },
        {
            title: deployment.name,
            href: showDeployment.url(deploymentId),
        },
    ];

    const deviceBasedTaskTypes = new Set([
        'UPDATE_SYSTEM_INFO',
        'ASSIGN_DEVICE_FUNCTION',
        'PREPROVISION_DEVICE_TO_GROUP',
        'MOVE_DEVICE_TO_GROUP',
        'ASSOCIATE_SITE_AND_NAME',
        'CREATE_VSF_PROFILE',
        'CREATE_VSX_PROFILE',
        'CONFIGURE_MIRROR_SESSION',
        'ADD_VLANS_TO_DEVICE_GROUP',
        'ASSIGN_SUBSCRIPTION',
        'UNASSIGN_SUBSCRIPTION',
    ]);

    const isDeviceBasedTask = (task_type: string) =>
        deviceBasedTaskTypes.has(task_type);

    const filteredLaunchTasks = useMemo(
        () => tasks.filter((task) => taskMatchesSearch(task, taskSearch)),
        [tasks, taskSearch],
    );

    const launchTasksByCategory = useMemo(
        () => groupTasksByLaunchCategory(filteredLaunchTasks),
        [filteredLaunchTasks],
    );

    const hasVisibleLaunchTasks = filteredLaunchTasks.length > 0;

    const visibleTaskCategories = useMemo(
        () =>
            TASK_LAUNCH_CATEGORIES.filter(
                (category) => launchTasksByCategory[category.id].length > 0,
            ),
        [launchTasksByCategory],
    );

    const selectedCategoryTasks =
        selectedTaskCategoryId !== null
            ? launchTasksByCategory[selectedTaskCategoryId]
            : [];

    useEffect(() => {
        if (
            selectedTaskCategoryId !== null &&
            launchTasksByCategory[selectedTaskCategoryId].length === 0
        ) {
            setSelectedTaskCategoryId(null);
        }
    }, [launchTasksByCategory, selectedTaskCategoryId]);

    function renderTaskCard(task: LaunchTask) {
        const TaskComponent = isDeviceBasedTask(task.task_type)
            ? TaskCard
            : TaskItemsCard;

        return (
            <TaskComponent
                key={task.task_type}
                task={task.task_type}
                task_friendly_name={task.friendly_name}
                task_friendly_description={task.friendly_description}
                required_columns={task.required_columns}
                devices={devicesForTasks}
                deployment={{
                    id: deployment.id,
                    name: deployment.name,
                }}
                requiresClassicCentral={classicCentralTaskTypes.has(
                    task.task_type,
                )}
                available_subscriptions={
                    task.task_type === 'ASSIGN_SUBSCRIPTION'
                        ? available_subscriptions
                        : undefined
                }
                licensing_synced_at={
                    task.task_type === 'ASSIGN_SUBSCRIPTION' ||
                    task.task_type === 'UNASSIGN_SUBSCRIPTION'
                        ? licensing_synced_at
                        : undefined
                }
                license_tags={
                    task.task_type === 'ASSIGN_SUBSCRIPTION'
                        ? license_tags
                        : undefined
                }
                license_type_options={
                    task.task_type === 'ASSIGN_SUBSCRIPTION'
                        ? (license_type_options as LicenseTypeOption[])
                        : undefined
                }
                cx_firmware_versions={
                    task.task_type === 'ADD_VLANS_TO_DEVICE_GROUP'
                        ? cx_firmware_versions
                        : undefined
                }
                central_firmware_error={
                    task.task_type === 'ADD_VLANS_TO_DEVICE_GROUP'
                        ? central_firmware_error
                        : undefined
                }
            />
        );
    }

    function handleSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(storeMany(deployment.id).url);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex w-full flex-col gap-6 px-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <h1 className="text-3xl font-semibold">{deployment.name}</h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="gap-2"
                            data-test="download-sample-csv"
                            onClick={downloadSampleDeviceCsv}
                        >
                            <Download className="size-4" aria-hidden />
                            Sample CSV
                        </Button>
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button data-test="add-devices">
                                    Add Devices
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>Add Device</DialogTitle>
                                <DialogDescription>
                                    Add devices to this deployment
                                </DialogDescription>
                                <Form
                                    action={storeMany(deployment.id).url}
                                    method="POST"
                                    onSuccess={() => {
                                        toast.success(
                                            'Devices added successfully',
                                        );
                                        setSubmitting(false);
                                    }}
                                    onError={() => {
                                        toast.error('Failed to add devices');
                                        setSubmitting(false);
                                    }}
                                    data-test="add-devices-form"
                                    className="flex flex-col gap-4"
                                    as="form"
                                    encType="multipart/form-data"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        setSubmitting(true);
                                        handleSubmit(e);
                                    }}
                                >
                                    <input
                                        type="file"
                                        name="devices"
                                        onChange={(e) => {
                                            const file =
                                                e.target.files?.[0] ?? null;
                                            setData('devices', file);
                                        }}
                                        className="block cursor-pointer rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400 dark:placeholder-gray-400"
                                        disabled={submitting}
                                    />
                                    {errors && Object.keys(errors).length > 0 && (
                                        <div className="space-y-2 text-xs text-red-500">
                                            {(() => {
                                                const {
                                                    headerErrors,
                                                    rowErrors,
                                                    otherErrors,
                                                } = groupCsvUploadErrors(errors);

                                                return (
                                                    <>
                                                        {headerErrors.length >
                                                            0 && (
                                                            <div className="space-y-1">
                                                                <p className="font-medium">
                                                                    CSV header
                                                                    issues
                                                                </p>
                                                                {headerErrors.map(
                                                                    ({
                                                                        key,
                                                                        message,
                                                                    }) => (
                                                                        <p
                                                                            key={
                                                                                key
                                                                            }
                                                                        >
                                                                            {
                                                                                message
                                                                            }
                                                                        </p>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                        {rowErrors.length >
                                                            0 && (
                                                            <div className="space-y-1">
                                                                <p className="font-medium">
                                                                    Row issues
                                                                </p>
                                                                {rowErrors.map(
                                                                    ({
                                                                        key,
                                                                        message,
                                                                    }) => (
                                                                        <p
                                                                            key={
                                                                                key
                                                                            }
                                                                        >
                                                                            <span className="font-medium">
                                                                                {
                                                                                    key
                                                                                }
                                                                                :
                                                                            </span>{' '}
                                                                            {
                                                                                message
                                                                            }
                                                                        </p>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                        {otherErrors.map(
                                                            ({
                                                                key,
                                                                message,
                                                            }) => (
                                                                <p key={key}>
                                                                    {key}:{' '}
                                                                    {message}
                                                                </p>
                                                            ),
                                                        )}
                                                    </>
                                                );
                                            })()}
                                        </div>
                                    )}
                                    <DialogFooter className="mt-4 flex-row-reverse sm:justify-start">
                                        <Button
                                            data-test="upload-devices"
                                            type="submit"
                                        >
                                            Add Devices
                                        </Button>
                                        {progress && (
                                            <progress
                                                value={progress.percentage}
                                                max="100"
                                            >
                                                {progress.percentage}%
                                            </progress>
                                        )}
                                    </DialogFooter>
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div className="flex flex-wrap justify-center gap-2">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button asChild>
                                <a
                                    href={criticalCheckDeployment(
                                        deploymentId,
                                    ).url}
                                >
                                    Critical configuration check
                                </a>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            Compare LAG and VLAN config in Central with this
                            deployment, and view static routes and DNS profiles.
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                asChild
                                variant="default"
                                className="hover:bg-pink-500 hover:text-white"
                            >
                                <a
                                    href={
                                        selectedIds.length > 0
                                            ? `${provisionDeployment(deploymentId).url}?device_ids=${selectedIds.join(',')}`
                                            : provisionDeployment(
                                                  deploymentId,
                                              ).url
                                    }
                                    data-test="run-provisioning-workflow"
                                >
                                    <Crown className="size-4" aria-hidden />
                                    I'm a Diva
                                </a>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            {selectedIds.length > 0
                                ? `Run full provisioning for ${selectedCount} selected device(s).`
                                : 'Open the provisioning workflow page for this deployment.'}
                        </TooltipContent>
                    </Tooltip>
                </div>

                <div className="mt-6 space-y-10">
                <section className="w-full">
                    <h2 className="text-xl font-semibold">Latest Tasks</h2>
                    {latest_tasks.length > 0 ? (
                        <div className="mx-auto mt-2 flex flex-wrap justify-center gap-2">
                            {latest_tasks.map((task) => (
                                <Card key={task.id} className="max-w-sm px-2">
                                    <CardTitle className="text-center text-xs">
                                        {task.friendly_name}
                                    </CardTitle>
                                    <CardDescription className="text-center text-xs">
                                        latest updated: {task.human_updated_at}
                                    </CardDescription>
                                    <CardContent className="text-sm">
                                        <div className="flex justify-between space-x-2">
                                            <p>Devices: {task.devices_count}</p>
                                            <p>Status: {task.status}</p>
                                        </div>
                                        <a
                                            href={showTask(task.id).url}
                                            className="text-emerald-500 hover:underline"
                                        >
                                            View Details
                                        </a>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    ) : null}
                </section>

                <section className="w-full">
                    <h2 className="text-xl font-semibold">Devices</h2>
                    {devicesFromServer.length > 0 ||
                    deviceTableSearch.trim() !== '' ? (
                        <>
                            <div className="mt-2 mb-2 flex flex-wrap items-center justify-between gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        selectedCount === 0 || syncingScopeIds
                                    }
                                    data-test="force-sync-device-scope-ids"
                                    onClick={handleForceSyncScopeIds}
                                >
                                    {isAllFilteredSelected
                                        ? 'force sync scope-id for all devices'
                                        : 'force sync scope-id for selected'}
                                </Button>
                                <div className="relative w-full max-w-sm sm:ml-auto">
                                    <Search
                                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <Input
                                        type="search"
                                        value={deviceTableSearch}
                                        onChange={(e) =>
                                            setDeviceTableSearch(e.target.value)
                                        }
                                        placeholder="Search name, serial, or function…"
                                        className="pl-9"
                                        data-test="devices-search"
                                        aria-label="Search devices by name, serial, or device function"
                                    />
                                </div>
                            </div>
                            {showSelectAllFilteredBanner ? (
                                <div
                                    className="mb-2 flex flex-wrap items-center gap-4"
                                    data-test="select-all-filtered-devices-banner"
                                >
                                    <p className="text-sm text-muted-foreground">
                                        All {devicesFromServer.length} devices on
                                        this page are selected.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="default"
                                        size="sm"
                                        className="shrink-0"
                                        data-test="select-all-filtered-devices"
                                        onClick={() => {
                                            setAllFilteredSelected(true);
                                            setRowSelection({});
                                        }}
                                    >
                                        Select all {devicesPaginator.total}{' '}
                                        devices matching this search
                                    </Button>
                                </div>
                            ) : null}
                            <DataTable<DeviceDef, unknown>
                                data={devicesFromServer}
                                columns={deviceTableColumns}
                                getRowId={(row) => String(row.id)}
                                enableRowSelection
                                rowSelection={rowSelection}
                                onRowSelectionChange={(updater) => {
                                    setAllFilteredSelected(false);
                                    setRowSelection(updater);
                                }}
                            />
                            {devicesPaginator.total >
                                devicesPaginator.per_page && (
                                <LaravelPaginator
                                    TPaginator={devicesPaginator}
                                />
                            )}
                        </>
                    ) : (
                        <p className="mt-2">No devices assigned to this deployment</p>
                    )}
                </section>

                <section className="w-full space-y-4">
                    <h2 className="text-xl font-semibold">Launch tasks</h2>
                    <div className="relative w-full max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            value={taskSearch}
                            onChange={(e) => setTaskSearch(e.target.value)}
                            placeholder="Search tasks by name, description, or required columns…"
                            className="pl-9"
                            data-test="tasks-search"
                            aria-label="Search tasks by name, description, or required columns"
                        />
                    </div>
                    {visibleTaskCategories.length > 0 ? (
                        <div
                            className="flex flex-wrap gap-2"
                            role="group"
                            aria-label="Task groups"
                        >
                            {visibleTaskCategories.map((category) => (
                                <Button
                                    key={category.id}
                                    type="button"
                                    variant={
                                        selectedTaskCategoryId === category.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    aria-pressed={
                                        selectedTaskCategoryId === category.id
                                    }
                                    data-test={`task-group-${category.id}`}
                                    onClick={() =>
                                        setSelectedTaskCategoryId(category.id)
                                    }
                                >
                                    {category.label}
                                </Button>
                            ))}
                        </div>
                    ) : null}
                    <div data-test="task-group-panel">
                        {taskSearch.trim() !== '' && !hasVisibleLaunchTasks ? (
                            <p className="text-muted-foreground text-sm">
                                No tasks match your search.
                            </p>
                        ) : selectedTaskCategoryId === null ? (
                            <p className="text-muted-foreground text-sm">
                                Select a task group above.
                            </p>
                        ) : (
                            <div className="flex flex-wrap justify-start gap-2">
                                {selectedCategoryTasks.map((task) =>
                                    renderTaskCard(task),
                                )}
                            </div>
                        )}
                    </div>
                </section>
                </div>
            </div>
        </AppLayout>
    );
}
