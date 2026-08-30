import { ChevronDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
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
    overall_status: string;
    current_step_label: string | null;
    status_message: string | null;
    steps: WorkflowDeviceStatusStep[];
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
}: {
    device: WorkflowDeviceStatusRow;
    forceOpen: boolean;
}) {
    const [open, setOpen] = useState(false);
    const isOpen = forceOpen || open;

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
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function WorkflowDeviceStatusList({
    devices,
}: {
    devices: WorkflowDeviceStatusRow[];
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
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
