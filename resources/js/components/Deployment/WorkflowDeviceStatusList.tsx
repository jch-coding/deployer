import { ChevronDown, RotateCcw, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import {
    deviceHasExplicitName,
    formatDeviceLabel,
} from '@/lib/device-label';
import { workflowDeviceMatchesSearch } from '@/lib/workflow-device-search';
import { cn } from '@/lib/utils';
import { restart as restartWorkflowDevice } from '@/routes/provisioning_workflow_devices';

const selectClassName =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export type WorkflowDeviceRestartableStep = {
    step_key: string;
    label: string;
};

export type WorkflowDeviceStatusStep = {
    step_key: string;
    label: string;
    status: string;
    message: string | null;
    order: number;
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

function formatDeviceRowTitle(name: string, serial: string): string {
    const label = formatDeviceLabel(name, serial);
    if (deviceHasExplicitName(name, serial)) {
        return `${label} (${serial.trim()})`;
    }

    return label;
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

function WorkflowDeviceRow({
    device,
    forceOpen,
    showRestartControls,
}: {
    device: WorkflowDeviceStatusRow;
    forceOpen: boolean;
    showRestartControls: boolean;
}) {
    const [open, setOpen] = useState(false);
    const isOpen = forceOpen || open;
    const restartableSteps = device.restartable_steps ?? [];
    const [restartStep, setRestartStep] = useState(
        restartableSteps[0]?.step_key ?? '',
    );

    return (
        <Collapsible
            open={isOpen}
            onOpenChange={setOpen}
            data-test={`workflow-device-row-${device.device_id}`}
        >
            <div className="flex items-center gap-2 border-b border-border py-2.5 last:border-0">
                <CollapsibleTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        className="flex h-auto min-w-0 flex-1 items-center justify-between gap-3 px-2 py-1.5"
                        aria-label={
                            isOpen
                                ? `Collapse ${formatDeviceRowTitle(device.name, device.serial)}`
                                : `Expand ${formatDeviceRowTitle(device.name, device.serial)}`
                        }
                    >
                        <span className="truncate text-left text-sm font-medium">
                            {formatDeviceRowTitle(device.name, device.serial)}
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
                            key={step.step_key}
                            className="flex items-start gap-2 text-xs"
                            data-test={`workflow-device-step-${device.device_id}-${step.step_key}`}
                        >
                            <span
                                className={cn(
                                    'mt-1 size-2 shrink-0 rounded-full',
                                    statusColor(step.status),
                                )}
                            />
                            <div className="min-w-0 flex-1">
                                <span className="font-medium">{step.label}</span>
                                <span className="ml-2 uppercase text-muted-foreground">
                                    {step.status}
                                </span>
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
                            value={restartStep}
                            onChange={(event) =>
                                setRestartStep(event.target.value)
                            }
                        >
                            {restartableSteps.map((step) => (
                                <option
                                    key={step.step_key}
                                    value={step.step_key}
                                >
                                    {step.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    restartWorkflowDevice(device.id).url,
                                    { from_step: restartStep },
                                )
                            }
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
    showRestartControls = false,
}: {
    devices: WorkflowDeviceStatusRow[];
    showRestartControls?: boolean;
}) {
    const [search, setSearch] = useState('');

    const filteredDevices = useMemo(
        () =>
            devices.filter((device) =>
                workflowDeviceMatchesSearch(device, search),
            ),
        [devices, search],
    );

    const forceOpen = search.trim() !== '';

    return (
        <div className="space-y-3">
            <div className="relative">
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
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
