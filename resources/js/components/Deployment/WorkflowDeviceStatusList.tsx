import { ChevronDown, RotateCcw, Search, SkipForward } from 'lucide-react';
import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { formatDeviceTitle } from '@/lib/device-label';
import {
    INSTALL_ONLINE_BADGE_OPTIONS,
    installOnlineBadgeClass,
    installOnlineBadgeKey,
    installOnlineBadgeLabel,
    type InstallOnlineBadgeKey,
} from '@/lib/workflow-device-install-online';
import { workflowDeviceMatchesSearch } from '@/lib/workflow-device-search';
import { cn } from '@/lib/utils';
import { bulkUpdateMetadata } from '@/routes/deployments';
import {
    override as overrideWorkflowDeviceStep,
    restart as restartWorkflowDevice,
} from '@/routes/provisioning_workflow_devices';

const selectClassName =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

const compactSelectClassName =
    'h-8 rounded-md border border-input bg-transparent px-2 py-1 text-xs shadow-xs';

export type WorkflowDeviceRestartableStep = {
    id?: number;
    step_key: string;
    label: string;
    order: number;
};

export type WorkflowDeviceStatusStep = {
    id?: number;
    step_key: string;
    label: string;
    status: string;
    message: string | null;
    order: number;
    user_overridden?: boolean;
    can_override?: boolean;
};

export type WorkflowDeviceStatusRow = {
    id: number;
    device_id: number;
    name: string;
    serial: string;
    device_function?: string | null;
    mac_address?: string | null;
    site_name?: string | null;
    group?: string | null;
    is_installed?: boolean | null;
    is_online?: boolean;
    overall_status: string;
    current_step_label: string | null;
    status_message: string | null;
    steps: WorkflowDeviceStatusStep[];
    restartable_steps?: WorkflowDeviceRestartableStep[];
};

function statusColor(status: string): string {
    switch (status) {
        case 'completed':
        case 'ok':
            return 'bg-emerald-500';
        case 'failed':
        case 'warn':
            return 'bg-red-500';
        case 'in_progress':
            return 'bg-blue-500';
        case 'unchecked':
            return 'bg-amber-500';
        case 'skipped':
            return 'bg-muted';
        default:
            return 'bg-muted-foreground/30';
    }
}

function deviceCurrentStepSummary(device: WorkflowDeviceStatusRow): string {
    if (device.current_step_label) {
        return device.current_step_label;
    }

    if (device.overall_status === 'completed') {
        return 'Completed';
    }

    if (device.overall_status === 'failed') {
        return 'Failed';
    }

    return 'Waiting';
}

function stepInstanceKey(step: { id?: number; order: number; step_key: string }): string {
    if (step.id != null) {
        return String(step.id);
    }

    return `${step.order}-${step.step_key}`;
}

function WorkflowDeviceRow({
    device,
    forceOpen,
    showRestartControls,
    selected,
    onSelectedChange,
}: {
    device: WorkflowDeviceStatusRow;
    forceOpen: boolean;
    showRestartControls: boolean;
    selected: boolean;
    onSelectedChange: (selected: boolean) => void;
}) {
    const [open, setOpen] = useState(false);
    const [savingInstalled, setSavingInstalled] = useState(false);
    const isOpen = forceOpen || open;
    const restartableSteps = device.restartable_steps ?? [];
    const defaultRestartOrder =
        restartableSteps[0]?.order != null
            ? String(restartableSteps[0].order)
            : '';
    const [restartStepOrder, setRestartStepOrder] = useState(defaultRestartOrder);
    // Polling can populate restartable_steps after mount; keep selection valid.
    const selectedRestartOrder = restartableSteps.some(
        (step) => String(step.order) === restartStepOrder,
    )
        ? restartStepOrder
        : defaultRestartOrder;
    const badgeKey = installOnlineBadgeKey(
        device.is_installed,
        device.is_online,
    );
    const installedSelectValue =
        device.is_installed === true ? 'true' : 'false';

    return (
        <Collapsible
            open={isOpen}
            onOpenChange={setOpen}
            data-test={`workflow-device-row-${device.device_id}`}
        >
            <div className="flex items-center gap-2 border-b border-border py-2.5 last:border-0">
                <Checkbox
                    checked={selected}
                    onCheckedChange={(checked) =>
                        onSelectedChange(checked === true)
                    }
                    aria-label={`Select ${formatDeviceTitle(device.name, device.serial)}`}
                    data-test={`workflow-device-select-${device.device_id}`}
                    className="ml-2 shrink-0"
                />
                <CollapsibleTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        className="flex h-auto min-w-0 flex-1 items-center justify-between gap-3 px-2 py-1.5"
                        aria-label={
                            isOpen
                                ? `Collapse ${formatDeviceTitle(device.name, device.serial)}`
                                : `Expand ${formatDeviceTitle(device.name, device.serial)}`
                        }
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="truncate text-left text-sm font-medium">
                                {formatDeviceTitle(device.name, device.serial)}
                            </span>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'shrink-0 font-normal',
                                    installOnlineBadgeClass(badgeKey),
                                )}
                                data-test={`workflow-device-install-online-${device.device_id}`}
                            >
                                {installOnlineBadgeLabel(badgeKey)}
                            </Badge>
                        </span>
                        <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">
                            {deviceCurrentStepSummary(device)}
                        </span>
                        <ChevronDown
                            className={cn(
                                'size-4 shrink-0 text-muted-foreground transition-transform',
                                isOpen && 'rotate-180',
                            )}
                            aria-hidden
                        />
                    </Button>
                </CollapsibleTrigger>
                <select
                    className={cn(compactSelectClassName, 'mr-2 shrink-0')}
                    value={installedSelectValue}
                    disabled={savingInstalled}
                    aria-label={`Installed status for ${formatDeviceTitle(device.name, device.serial)}`}
                    data-test={`workflow-device-installed-select-${device.device_id}`}
                    onClick={(event) => event.stopPropagation()}
                    onChange={(event) => {
                        const next = event.target.value === 'true';
                        if (device.is_installed === next) {
                            return;
                        }
                        setSavingInstalled(true);
                        router.patch(
                            `/devices/${device.device_id}`,
                            { is_installed: next },
                            {
                                preserveScroll: true,
                                only: ['workflow'],
                                onFinish: () => setSavingInstalled(false),
                            },
                        );
                    }}
                >
                    <option value="true">Installed</option>
                    <option value="false">Not installed</option>
                </select>
            </div>
            <CollapsibleContent className="pb-3 pl-4 pr-2">
                <p className="mb-2 text-xs text-muted-foreground sm:hidden">
                    {deviceCurrentStepSummary(device)}
                </p>
                {device.status_message ? (
                    <p className="mb-2 text-xs text-muted-foreground">
                        {device.status_message}
                    </p>
                ) : null}
                <div className="space-y-1">
                    {device.steps.map((step) => (
                        <div
                            key={stepInstanceKey(step)}
                            className="flex items-start gap-2 text-xs"
                            data-test={`workflow-device-step-${device.device_id}-${step.order}-${step.step_key}`}
                        >
                            <span
                                className={cn(
                                    'mt-1 size-2 shrink-0 rounded-full',
                                    statusColor(step.status),
                                )}
                            />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{step.label}</span>
                                    <span className="uppercase text-muted-foreground">
                                        {step.status}
                                    </span>
                                    {step.user_overridden ? (
                                        <Badge
                                            variant="outline"
                                            className="font-normal"
                                            data-test={`workflow-step-user-override-${device.device_id}-${step.order}-${step.step_key}`}
                                        >
                                            User override
                                        </Badge>
                                    ) : null}
                                    {step.can_override ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="h-6 px-2 text-xs"
                                            data-test={`workflow-step-skip-${device.device_id}-${step.order}-${step.step_key}`}
                                            onClick={() =>
                                                router.post(
                                                    overrideWorkflowDeviceStep(
                                                        device.id,
                                                    ).url,
                                                    {
                                                        step_order: step.order,
                                                    },
                                                )
                                            }
                                        >
                                            <SkipForward className="mr-1 size-3" />
                                            Skip step
                                        </Button>
                                    ) : null}
                                </div>
                                {step.message ? (
                                    <p className="text-muted-foreground">
                                        {step.message}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
                {showRestartControls && restartableSteps.length > 0 ? (
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                        <select
                            className={cn(selectClassName, 'max-w-[220px]')}
                            value={selectedRestartOrder}
                            onChange={(event) =>
                                setRestartStepOrder(event.target.value)
                            }
                        >
                            {restartableSteps.map((step) => (
                                <option
                                    key={stepInstanceKey(step)}
                                    value={String(step.order)}
                                >
                                    {step.label}
                                    {restartableSteps.filter(
                                        (other) => other.step_key === step.step_key,
                                    ).length > 1
                                        ? ` (#${step.order})`
                                        : ''}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={selectedRestartOrder === ''}
                            onClick={() => {
                                const fromStepOrder = Number(selectedRestartOrder);
                                if (
                                    !Number.isFinite(fromStepOrder) ||
                                    fromStepOrder < 1
                                ) {
                                    return;
                                }

                                router.post(
                                    restartWorkflowDevice(device.id).url,
                                    {
                                        from_step_order: fromStepOrder,
                                    },
                                );
                            }}
                        >
                            <RotateCcw className="mr-1 size-3" />
                            Restart from step
                        </Button>
                    </div>
                ) : null}
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function WorkflowDeviceStatusList({
    devices,
    deploymentId,
    showRestartControls = false,
}: {
    devices: WorkflowDeviceStatusRow[];
    deploymentId: number;
    showRestartControls?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [installOnlineFilter, setInstallOnlineFilter] = useState<
        '' | InstallOnlineBadgeKey
    >('');
    const [selectedDeviceIds, setSelectedDeviceIds] = useState<number[]>([]);
    const [bulkInstalled, setBulkInstalled] = useState<'true' | 'false' | ''>(
        '',
    );
    const [applyingBulk, setApplyingBulk] = useState(false);

    const filteredDevices = useMemo(
        () =>
            devices.filter((device) => {
                if (!workflowDeviceMatchesSearch(device, search)) {
                    return false;
                }

                if (installOnlineFilter === '') {
                    return true;
                }

                return (
                    installOnlineBadgeKey(
                        device.is_installed,
                        device.is_online,
                    ) === installOnlineFilter
                );
            }),
        [devices, search, installOnlineFilter],
    );

    const filteredDeviceIds = useMemo(
        () => filteredDevices.map((device) => device.device_id),
        [filteredDevices],
    );

    const selectedFilteredCount = useMemo(
        () =>
            selectedDeviceIds.filter((id) =>
                filteredDeviceIds.includes(id),
            ).length,
        [filteredDeviceIds, selectedDeviceIds],
    );

    const allFilteredSelected =
        filteredDeviceIds.length > 0 &&
        filteredDeviceIds.every((id) => selectedDeviceIds.includes(id));

    const someFilteredSelected =
        selectedFilteredCount > 0 && !allFilteredSelected;

    const forceOpen = search.trim() !== '';

    const toggleSelectAllFiltered = (checked: boolean) => {
        if (checked) {
            setSelectedDeviceIds((prev) =>
                Array.from(new Set([...prev, ...filteredDeviceIds])),
            );
            return;
        }

        setSelectedDeviceIds((prev) =>
            prev.filter((id) => !filteredDeviceIds.includes(id)),
        );
    };

    const handleBulkApply = () => {
        if (bulkInstalled === '' || selectedFilteredCount === 0) {
            return;
        }

        const deviceIds = selectedDeviceIds.filter((id) =>
            filteredDeviceIds.includes(id),
        );

        setApplyingBulk(true);
        router.post(
            bulkUpdateMetadata.url(deploymentId),
            {
                device_ids: deviceIds,
                is_installed: bulkInstalled === 'true',
            },
            {
                preserveScroll: true,
                only: ['workflow'],
                onSuccess: () => {
                    setSelectedDeviceIds([]);
                    setBulkInstalled('');
                },
                onFinish: () => setApplyingBulk(false),
            },
        );
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row">
                <div className="relative min-w-0 flex-1">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search devices and steps…"
                        className="pl-9"
                        data-test="workflow-device-search"
                    />
                </div>
                <select
                    value={installOnlineFilter}
                    onChange={(event) =>
                        setInstallOnlineFilter(
                            event.target.value as '' | InstallOnlineBadgeKey,
                        )
                    }
                    className={selectClassName}
                    data-test="workflow-device-install-online-filter"
                >
                    <option value="">All install/online statuses</option>
                    {INSTALL_ONLINE_BADGE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            {filteredDevices.length > 0 ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Checkbox
                        checked={
                            allFilteredSelected
                                ? true
                                : someFilteredSelected
                                  ? 'indeterminate'
                                  : false
                        }
                        onCheckedChange={(checked) =>
                            toggleSelectAllFiltered(checked === true)
                        }
                        aria-label="Select all matching devices"
                        data-test="workflow-device-select-all"
                    />
                    <span className="text-sm text-muted-foreground">
                        {selectedFilteredCount > 0
                            ? `${selectedFilteredCount} selected`
                            : 'Select devices'}
                    </span>
                    {selectedFilteredCount > 0 ? (
                        <>
                            <select
                                className={selectClassName}
                                value={bulkInstalled}
                                onChange={(event) =>
                                    setBulkInstalled(
                                        event.target.value as
                                            | 'true'
                                            | 'false'
                                            | '',
                                    )
                                }
                                aria-label="Bulk installed status"
                                data-test="workflow-bulk-installed-select"
                            >
                                <option value="">Installed status…</option>
                                <option value="true">Installed</option>
                                <option value="false">Not installed</option>
                            </select>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    bulkInstalled === '' || applyingBulk
                                }
                                data-test="workflow-bulk-installed-apply"
                                onClick={handleBulkApply}
                            >
                                {applyingBulk
                                    ? 'Applying…'
                                    : `Apply to selected (${selectedFilteredCount})`}
                            </Button>
                        </>
                    ) : null}
                </div>
            ) : null}
            {filteredDevices.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No devices match your search.
                </p>
            ) : (
                <div className="rounded-md border">
                    {filteredDevices.map((device) => (
                        <WorkflowDeviceRow
                            key={device.id}
                            device={device}
                            forceOpen={forceOpen}
                            showRestartControls={showRestartControls}
                            selected={selectedDeviceIds.includes(
                                device.device_id,
                            )}
                            onSelectedChange={(next) => {
                                setSelectedDeviceIds((prev) => {
                                    if (next) {
                                        if (prev.includes(device.device_id)) {
                                            return prev;
                                        }
                                        return [...prev, device.device_id];
                                    }
                                    return prev.filter(
                                        (id) => id !== device.device_id,
                                    );
                                });
                            }}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
