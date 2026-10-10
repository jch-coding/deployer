import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2, Workflow } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { validateCustomWorkflowStepOrder } from '@/lib/custom-workflow-step-order';
import { cn } from '@/lib/utils';
import { append_steps as appendStepsWorkflow } from '@/routes/provisioning_workflows';

export type AppendableStep = {
    step_key: string;
    label: string;
    order: number;
};

export type AppendStepsWorkflowPayload = {
    id: number;
    steps?: string[] | null;
    appendable_steps?: AppendableStep[];
    needs_licensing_for_append?: boolean;
};

const selectClassName =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

type AppendStepsDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workflow: AppendStepsWorkflowPayload;
    reloadOnly?: string[];
};

export default function AppendStepsDialog({
    open,
    onOpenChange,
    workflow,
    reloadOnly,
}: AppendStepsDialogProps) {
    const existingSteps = workflow.steps ?? [];
    const appendable = workflow.appendable_steps ?? [];
    const [selectedSteps, setSelectedSteps] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [licenseTag, setLicenseTag] = useState('');
    const [licenseType, setLicenseType] = useState('');
    const [onlyUpdateDifferentNames, setOnlyUpdateDifferentNames] =
        useState(false);

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

    const availableToAdd = appendable;

    const stepOrderError = validateCustomWorkflowStepOrder(
        [...existingSteps, ...selectedSteps],
        labelsByKey,
        { allowDuplicates: true },
    );

    const includesLicensing = selectedSteps.includes('verify_licensing');
    const includesNameDevice = selectedSteps.includes('name_device');
    const showLicensingFields =
        Boolean(workflow.needs_licensing_for_append) && includesLicensing;

    const resetForm = () => {
        setSelectedSteps([]);
        setLicenseTag('');
        setLicenseType('');
        setOnlyUpdateDifferentNames(false);
        setSubmitting(false);
    };

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            resetForm();
        }
        onOpenChange(next);
    };

    const addStep = (stepKey: string) => {
        if (!stepKey) {
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

        const payload: {
            steps: string[];
            only_update_different_names?: boolean;
            licensing_mode?: string;
            license_tag?: string;
            license_type?: string;
        } = {
            steps: selectedSteps,
        };
        if (includesNameDevice) {
            payload.only_update_different_names = onlyUpdateDifferentNames;
        }
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
            ...(reloadOnly ? { only: reloadOnly } : {}),
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

                    {includesNameDevice ? (
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={onlyUpdateDifferentNames}
                                onChange={(e) =>
                                    setOnlyUpdateDifferentNames(e.target.checked)
                                }
                                data-test="only-update-different-names"
                            />
                            <span>
                                Only update names that differ from Central
                                <span className="block text-xs text-muted-foreground">
                                    Queries Central system info first and skips
                                    devices whose hostname already matches.
                                    Devices that are not Up in Classic Central
                                    are always marked failed.
                                </span>
                            </span>
                        </label>
                    ) : null}

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
