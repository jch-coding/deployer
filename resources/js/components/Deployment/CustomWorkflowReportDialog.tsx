import { useMemo, useState } from 'react';
import type { WorkflowDeviceStatusRow } from '@/components/Deployment/WorkflowDeviceStatusList';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    type CustomWorkflowReportIncludeFields,
    generateCustomWorkflowReportPdf,
    saveCustomWorkflowReportPdf,
    suggestedCustomWorkflowReportFilename,
    workflowDeviceStatusLabel,
} from '@/lib/custom-workflow-report-pdf';
import { cn } from '@/lib/utils';

type CustomWorkflowReportDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workflowName: string | null;
    deploymentName: string;
    summary: { in_progress: number; completed: number; failed: number };
    devices: WorkflowDeviceStatusRow[];
};

const OPTIONAL_COLUMNS: Array<{
    key: keyof CustomWorkflowReportIncludeFields;
    label: string;
}> = [
    { key: 'name', label: 'Name' },
    { key: 'device_function', label: 'Device function' },
    { key: 'mac_address', label: 'MAC' },
    { key: 'site_name', label: 'Site' },
    { key: 'group', label: 'Group' },
];

function workflowStatusBadgeClass(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'failed':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        default:
            return '';
    }
}

const defaultIncludeFields: CustomWorkflowReportIncludeFields = {
    name: false,
    device_function: false,
    mac_address: false,
    site_name: false,
    group: false,
};

export default function CustomWorkflowReportDialog({
    open,
    onOpenChange,
    workflowName,
    deploymentName,
    summary,
    devices,
}: CustomWorkflowReportDialogProps) {
    const [includeFields, setIncludeFields] =
        useState<CustomWorkflowReportIncludeFields>(defaultIncludeFields);
    const [deviceNotes, setDeviceNotes] = useState<Record<number, string>>(
        {},
    );
    const [saving, setSaving] = useState(false);

    const generatedAt = useMemo(() => new Date(), [open]);

    const toggleField = (key: keyof CustomWorkflowReportIncludeFields) => {
        setIncludeFields((prev) => ({ ...prev, [key]: !prev[key] }));
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            const blob = generateCustomWorkflowReportPdf(
                {
                    workflowName,
                    deploymentName,
                    generatedAt,
                    summary,
                },
                devices,
                includeFields,
                deviceNotes,
            );
            const filename = suggestedCustomWorkflowReportFilename(
                workflowName,
                generatedAt,
            );
            await saveCustomWorkflowReportPdf(blob, filename);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl"
                data-test="custom-workflow-report-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Custom workflow report</DialogTitle>
                    <DialogDescription>
                        {workflowName?.trim()
                            ? `${workflowName.trim()} — `
                            : ''}
                        {deploymentName}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-1 text-sm text-muted-foreground">
                    <p>Generated {generatedAt.toLocaleString()}</p>
                    <p>
                        Complete: {summary.completed} · In progress:{' '}
                        {summary.in_progress} · Failed: {summary.failed}
                    </p>
                </div>

                <div className="space-y-2">
                    <p className="text-sm font-medium">Include columns</p>
                    <div className="flex flex-wrap gap-4">
                        {OPTIONAL_COLUMNS.map((column) => (
                            <label
                                key={column.key}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={includeFields[column.key]}
                                    onCheckedChange={() =>
                                        toggleField(column.key)
                                    }
                                    data-test={`report-include-${column.key}`}
                                />
                                {column.label}
                            </label>
                        ))}
                    </div>
                </div>

                <div className="-mx-1 flex-1 space-y-3 overflow-y-auto px-1">
                    {devices.map((device) => (
                        <div
                            key={device.device_id}
                            className="space-y-2 rounded-md border p-3"
                            data-test={`report-device-row-${device.device_id}`}
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {device.serial}
                                </span>
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        workflowStatusBadgeClass(
                                            device.overall_status,
                                        ),
                                    )}
                                >
                                    {workflowDeviceStatusLabel(
                                        device.overall_status,
                                    )}
                                </Badge>
                            </div>

                            <div className="grid gap-1 text-sm text-muted-foreground sm:grid-cols-2">
                                {includeFields.name ? (
                                    <p>
                                        <span className="font-medium text-foreground">
                                            Name:
                                        </span>{' '}
                                        {device.name}
                                    </p>
                                ) : null}
                                {includeFields.device_function ? (
                                    <p>
                                        <span className="font-medium text-foreground">
                                            Device function:
                                        </span>{' '}
                                        {device.device_function ?? '—'}
                                    </p>
                                ) : null}
                                {includeFields.mac_address ? (
                                    <p>
                                        <span className="font-medium text-foreground">
                                            MAC:
                                        </span>{' '}
                                        {device.mac_address ?? '—'}
                                    </p>
                                ) : null}
                                {includeFields.site_name ? (
                                    <p>
                                        <span className="font-medium text-foreground">
                                            Site:
                                        </span>{' '}
                                        {device.site_name ?? '—'}
                                    </p>
                                ) : null}
                                {includeFields.group ? (
                                    <p>
                                        <span className="font-medium text-foreground">
                                            Group:
                                        </span>{' '}
                                        {device.group ?? '—'}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-1">
                                <Label
                                    htmlFor={`report-notes-${device.device_id}`}
                                    className="text-xs"
                                >
                                    Notes
                                </Label>
                                <textarea
                                    id={`report-notes-${device.device_id}`}
                                    value={deviceNotes[device.device_id] ?? ''}
                                    onChange={(e) =>
                                        setDeviceNotes((prev) => ({
                                            ...prev,
                                            [device.device_id]: e.target.value,
                                        }))
                                    }
                                    rows={2}
                                    className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive flex min-h-[4rem] w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="Add notes for this device…"
                                    data-test={`report-notes-${device.device_id}`}
                                />
                            </div>
                        </div>
                    ))}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => void handleSave()}
                        disabled={saving}
                        data-test="save-custom-workflow-report"
                    >
                        {saving ? 'Saving…' : 'Save report'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
