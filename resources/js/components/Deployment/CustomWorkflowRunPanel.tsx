import { router } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    Clock,
    FileText,
    Plus,
    Trash2,
    Workflow,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import CustomWorkflowReportDialog from '@/components/Deployment/CustomWorkflowReportDialog';
import WorkflowDeviceStatusList, {
    type WorkflowDeviceStatusRow,
} from '@/components/Deployment/WorkflowDeviceStatusList';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDeviceLabel } from '@/lib/device-label';
import { validateCustomWorkflowStepOrder } from '@/lib/custom-workflow-step-order';
import { cn } from '@/lib/utils';
import {
    append_steps as appendStepsWorkflow,
    cancel as cancelWorkflow,
    pause as pauseWorkflow,
    resume as resumeWorkflow,
} from '@/routes/provisioning_workflows';

export type AppendableStep = {
    step_key: string;
    label: string;
    order: number;
};

export type CustomWorkflowRunPayload = {
    id: number;
    name: string | null;
    status: string;
    is_terminal: boolean;
    steps?: string[] | null;
    summary: { in_progress: number; completed: number; failed: number };
    devices: WorkflowDeviceStatusRow[];
    licensing_failures: Array<{
        device_id: number;
        name: string;
        serial: string;
        message: string | null;
    }>;
    can_pause: boolean;
    can_cancel: boolean;
    can_resume: boolean;
    can_append_steps?: boolean;
    appendable_steps?: AppendableStep[];
    needs_licensing_for_append?: boolean;
    scheduled_at?: string | null;
};

const selectClassName =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

function SummaryCard({
    title,
    count,
    icon,
}: {
    title: string;
    count: number;
    icon: React.ReactNode;
}) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between pt-6">
                <div>
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="text-3xl font-semibold">{count}</p>
                </div>
                {icon}
            </CardContent>
        </Card>
    );
}

function AppendStepsDialog({
    open,
    onOpenChange,
    workflow,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workflow: CustomWorkflowRunPayload;
}) {
    const existingSteps = workflow.steps ?? [];
    const appendable = workflow.appendable_steps ?? [];
    const [selectedSteps, setSelectedSteps] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [licenseTag, setLicenseTag] = useState('');
    const [licenseType, setLicenseType] = useState('');

    const labelsByKey = useMemo(() => {
        const map: Record<string, string> = {};
        for (const step of appendable) {
            map[step.step_key] = step.label;
        }
        for (const key of existingSteps) {
            if (!map[key]) {
                map[key] = key;
            }
        }
        return map;
    }, [appendable, existingSteps]);

    const availableToAdd = appendable.filter(
        (step) => !selectedSteps.includes(step.step_key),
    );

    const stepOrderError = validateCustomWorkflowStepOrder(
        [...existingSteps, ...selectedSteps],
        labelsByKey,
    );

    const includesLicensing = selectedSteps.includes('verify_licensing');
    const showLicensingFields =
        Boolean(workflow.needs_licensing_for_append) && includesLicensing;

    const resetForm = () => {
        setSelectedSteps([]);
        setLicenseTag('');
        setLicenseType('');
        setSubmitting(false);
    };

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            resetForm();
        }
        onOpenChange(next);
    };

    const addStep = (stepKey: string) => {
        if (!stepKey || selectedSteps.includes(stepKey)) {
            return;
        }
        setSelectedSteps((prev) => [...prev, stepKey]);
    };

    const removeStep = (index: number) => {
        setSelectedSteps((prev) => prev.filter((_, i) => i !== index));
    };

    const moveStep = (index: number, direction: -1 | 1) => {
        setSelectedSteps((prev) => {
            const next = [...prev];
            const target = index + direction;
            if (target < 0 || target >= next.length) {
                return prev;
            }
            const tmp = next[index];
            next[index] = next[target];
            next[target] = tmp;
            return next;
        });
    };

    const submit = () => {
        if (selectedSteps.length === 0) {
            toast.error('Select at least one step to append.');
            return;
        }
        if (stepOrderError) {
            toast.error(stepOrderError);
            return;
        }

        const payload: Record<string, unknown> = {
            steps: selectedSteps,
        };
        if (showLicensingFields) {
            payload.licensing_mode = 'uniform';
            if (licenseTag.trim() !== '') {
                payload.license_tag = licenseTag.trim();
            }
            if (licenseType.trim() !== '') {
                payload.license_type = licenseType.trim();
            }
        }

        setSubmitting(true);
        router.post(appendStepsWorkflow(workflow.id).url, payload, {
            preserveScroll: true,
            onSuccess: () => {
                handleOpenChange(false);
            },
            onError: (errors) => {
                const message =
                    Object.values(errors)
                        .flat()
                        .find((value) => typeof value === 'string') ??
                    'Failed to append steps.';
                toast.error(String(message));
            },
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent
                className="max-w-lg"
                data-test="append-custom-workflow-steps-dialog"
            >
                <DialogTitle>Add steps</DialogTitle>
                <DialogDescription>
                    Append new steps to every device on this custom task.
                    Completed devices will start the new steps when the run is
                    active.
                </DialogDescription>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-sm font-medium">Steps to append</p>
                            <select
                                className={cn(selectClassName, 'max-w-xs')}
                                value=""
                                onChange={(e) => {
                                    if (e.target.value) {
                                        addStep(e.target.value);
                                    }
                                }}
                                data-test="append-workflow-step-select"
                            >
                                <option value="">Add step…</option>
                                {availableToAdd.map((step) => (
                                    <option
                                        key={step.step_key}
                                        value={step.step_key}
                                    >
                                        {step.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {stepOrderError ? (
                            <p
                                className="text-sm text-destructive"
                                data-test="append-step-order-error"
                            >
                                {stepOrderError}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                Combined order must still respect licensing →
                                preprovision → other steps.
                            </p>
                        )}
                        <div
                            className="space-y-2"
                            data-test="append-selected-steps"
                        >
                            {selectedSteps.length === 0 ? (
                                <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                    No steps selected yet.
                                </p>
                            ) : (
                                selectedSteps.map((stepKey, index) => (
                                    <div
                                        key={`${stepKey}-${index}`}
                                        className="flex items-center gap-2 rounded-md border px-2 py-1.5"
                                    >
                                        <Workflow className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="flex-1 text-sm">
                                            {existingSteps.length + index + 1}.{' '}
                                            {labelsByKey[stepKey] ?? stepKey}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => moveStep(index, -1)}
                                            disabled={index === 0}
                                            aria-label="Move up"
                                        >
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => moveStep(index, 1)}
                                            disabled={
                                                index === selectedSteps.length - 1
                                            }
                                            aria-label="Move down"
                                        >
                                            <ArrowDown className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeStep(index)}
                                            aria-label="Remove step"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {showLicensingFields ? (
                        <div
                            className="space-y-3 rounded-md border p-3"
                            data-test="append-licensing-fields"
                        >
                            <p className="text-sm font-medium">Licensing</p>
                            <p className="text-xs text-muted-foreground">
                                This run did not include licensing. Provide a
                                license tag and type if devices are missing CSV
                                licensing fields.
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    value={licenseTag}
                                    onChange={(e) =>
                                        setLicenseTag(e.target.value)
                                    }
                                    placeholder="License tag"
                                    data-test="append-license-tag"
                                />
                                <Input
                                    value={licenseType}
                                    onChange={(e) =>
                                        setLicenseType(e.target.value)
                                    }
                                    placeholder="License type"
                                    data-test="append-license-type"
                                />
                            </div>
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            selectedSteps.length === 0 ||
                            Boolean(stepOrderError) ||
                            submitting
                        }
                        data-test="confirm-append-workflow-steps"
                    >
                        <Plus className="mr-1 size-4" />
                        Append steps
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function CustomWorkflowRunPanel({
    workflow,
    title,
    deploymentName,
}: {
    workflow: CustomWorkflowRunPayload;
    title?: string;
    deploymentName: string;
}) {
    const [reportOpen, setReportOpen] = useState(false);
    const [appendOpen, setAppendOpen] = useState(false);
    const panelTitle =
        title ??
        (workflow.name ? `Custom run: ${workflow.name}` : 'Custom workflow run');

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">{panelTitle}</h2>
                    <p className="text-sm text-muted-foreground capitalize">
                        Status: {workflow.status.replace('_', ' ')}
                    </p>
                    {workflow.status === 'scheduled' && workflow.scheduled_at ? (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="custom-workflow-starts-at"
                        >
                            Starts{' '}
                            {new Intl.DateTimeFormat('en-US', {
                                timeZone: 'America/New_York',
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                                hour: 'numeric',
                                minute: '2-digit',
                                timeZoneName: 'short',
                            }).format(new Date(workflow.scheduled_at))}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-2">
                    {workflow.can_append_steps ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAppendOpen(true)}
                            data-test="append-custom-workflow-steps"
                        >
                            <Plus className="mr-1 size-4" />
                            Add steps
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setReportOpen(true)}
                        data-test="generate-custom-workflow-report"
                    >
                        <FileText className="mr-1 size-4" />
                        Generate report
                    </Button>
                    {workflow.can_pause ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(pauseWorkflow(workflow.id).url)
                            }
                            data-test="pause-custom-workflow"
                        >
                            Pause workflow
                        </Button>
                    ) : null}
                    {workflow.can_cancel ? (
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.post(cancelWorkflow(workflow.id).url)
                            }
                            data-test="cancel-custom-workflow"
                        >
                            Cancel workflow
                        </Button>
                    ) : null}
                    {workflow.can_resume ? (
                        <Button
                            onClick={() =>
                                router.post(resumeWorkflow(workflow.id).url)
                            }
                            data-test="resume-custom-workflow"
                        >
                            Resume workflow
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <SummaryCard
                    title="In progress"
                    count={workflow.summary.in_progress}
                    icon={<Clock className="size-5 text-blue-500" />}
                />
                <SummaryCard
                    title="Completed"
                    count={workflow.summary.completed}
                    icon={<CheckCircle2 className="size-5 text-emerald-500" />}
                />
                <SummaryCard
                    title="Failed"
                    count={workflow.summary.failed}
                    icon={<AlertCircle className="size-5 text-red-500" />}
                />
            </div>

            {workflow.licensing_failures.length > 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Licensing failures
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2 text-sm">
                            {workflow.licensing_failures.map((row) => (
                                <li
                                    key={row.device_id}
                                    className="rounded border p-2"
                                >
                                    <span className="font-medium">
                                        {formatDeviceLabel(row.name, row.serial)}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        ({row.serial})
                                    </span>
                                    {row.message ? (
                                        <p className="text-destructive">
                                            {row.message}
                                        </p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Devices</CardTitle>
                </CardHeader>
                <CardContent>
                    <WorkflowDeviceStatusList
                        devices={workflow.devices}
                        showRestartControls
                    />
                </CardContent>
            </Card>
            <CustomWorkflowReportDialog
                open={reportOpen}
                onOpenChange={setReportOpen}
                workflowName={workflow.name}
                deploymentName={deploymentName}
                summary={workflow.summary}
                devices={workflow.devices}
            />
            {workflow.can_append_steps ? (
                <AppendStepsDialog
                    open={appendOpen}
                    onOpenChange={setAppendOpen}
                    workflow={workflow}
                />
            ) : null}
        </div>
    );
}
