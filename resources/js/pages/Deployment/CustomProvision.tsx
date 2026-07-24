import { Link, router, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Loader2,
    Play,
    Plus,
    Trash2,
    Workflow,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import type { AvailableSubscription } from '@/components/licensing/LicenseSelect';
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
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { validateCustomWorkflowStepOrder } from '@/lib/custom-workflow-step-order';
import {
    deviceHasExplicitName,
    formatDeviceLabel,
    isApDevice,
} from '@/lib/device-label';
import type { LicenseTypeOption } from '@/lib/license-types';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import {
    custom_provision as customProvisionDeployment,
    show as showDeployment,
} from '@/routes/deployments';
import {
    preflight as preflightProvision,
    store as storeProvision,
} from '@/routes/deployments/provision';
import { destroy as destroyTemplate } from '@/routes/provisioning_workflow_templates';
import { cancel as cancelWorkflow } from '@/routes/provisioning_workflows';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeploymentDevice = {
    id: number;
    name: string;
    serial: string;
    device_function?: string;
    license_tag?: string | null;
    license_type?: string | null;
    group?: string | null;
};

type AvailableStep = {
    step_key: string;
    label: string;
    order: number;
};

type WorkflowTemplate = {
    id: number;
    name: string;
    steps: string[];
};

type WorkflowStep = {
    step_key: string;
    label: string;
    status: string;
    message: string | null;
    order: number;
};

type WorkflowDeviceCard = {
    id: number;
    device_id: number;
    name: string;
    serial: string;
    overall_status: string;
    current_step_key: string | null;
    current_step_label: string | null;
    failed_step_key: string | null;
    status_message: string | null;
    progress_percent: number;
    completed_steps: number;
    applicable_steps: number;
    steps: WorkflowStep[];
};

type WorkflowPayload = {
    id: number;
    name: string | null;
    status: string;
    steps: string[] | null;
    summary: { in_progress: number; completed: number; failed: number };
    devices: WorkflowDeviceCard[];
    is_terminal: boolean;
};

type PreflightStepResult = {
    step_key: string;
    label: string;
    status: 'ok' | 'warn' | 'unchecked';
    message: string;
};

type PreflightDeviceResult = {
    device_id: number;
    name: string;
    serial: string;
    steps: PreflightStepResult[];
};

type PreflightPayload = {
    has_warnings: boolean;
    devices: PreflightDeviceResult[];
};

type CustomProvisionPageProps = SharedData & {
    deployment: {
        id: number;
        name: string;
        devices: DeploymentDevice[];
    };
    workflow: WorkflowPayload | null;
    available_steps: AvailableStep[];
    templates: WorkflowTemplate[];
    license_tags: string[];
    available_subscriptions: AvailableSubscription[];
    license_type_options: LicenseTypeOption[];
    licensing_error: string | null;
    selected_device_ids?: number[];
    has_classic_webhook_secret?: boolean;
    has_classic_streaming_credentials?: boolean;
};

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

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

export default function CustomProvision() {
    const {
        current_client,
        deployment,
        workflow,
        available_steps: availableSteps = [],
        templates = [],
        license_tags,
        license_type_options,
        licensing_error,
        flash,
        selected_device_ids: selectedDeviceIdsProp = [],
        has_classic_webhook_secret: hasClassicWebhookSecret = false,
        has_classic_streaming_credentials: hasClassicStreamingCredentials = false,
    } = usePage<CustomProvisionPageProps>().props;

    const labelsByKey = useMemo(() => {
        const map: Record<string, string> = {};
        for (const step of availableSteps) {
            map[step.step_key] = step.label;
        }
        return map;
    }, [availableSteps]);

    const [selectedDeviceIds, setSelectedDeviceIds] = useState<number[]>(
        selectedDeviceIdsProp.length > 0
            ? selectedDeviceIdsProp
            : deployment.devices.map((d) => d.id),
    );
    const [selectedSteps, setSelectedSteps] = useState<string[]>([]);
    const [workflowName, setWorkflowName] = useState('');
    const [saveAsTemplate, setSaveAsTemplate] = useState(false);
    const [templateName, setTemplateName] = useState('');
    const [selectedTemplateId, setSelectedTemplateId] = useState<string>('');
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0);
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(10);
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(1);
    const [onlineDetectionMode, setOnlineDetectionMode] = useState<
        'poll' | 'webhook' | 'stream'
    >('poll');
    const [licensingMode, setLicensingMode] = useState<'uniform' | 'per_device'>(
        'uniform',
    );
    const [uniformLicenseTag, setUniformLicenseTag] = useState('');
    const [uniformLicenseType, setUniformLicenseType] = useState<
        LicenseTypeOption | ''
    >('');
    const [perDeviceLicense, setPerDeviceLicense] = useState<
        Record<number, { license_tag: string; license_type: LicenseTypeOption | '' }>
    >({});
    const [perDeviceApNames, setPerDeviceApNames] = useState<
        Record<number, string>
    >({});
    const [preflightLoading, setPreflightLoading] = useState(false);
    const [preflightOpen, setPreflightOpen] = useState(false);
    const [preflightResult, setPreflightResult] =
        useState<PreflightPayload | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    const shouldPoll = Boolean(workflow && !workflow.is_terminal);
    usePoll(shouldPoll ? 2000 : 0);

    const selectedDevices = useMemo(
        () =>
            deployment.devices.filter((device) =>
                selectedDeviceIds.includes(device.id),
            ),
        [deployment.devices, selectedDeviceIds],
    );

    const includesLicensing = selectedSteps.includes('verify_licensing');
    const includesNameDevice = selectedSteps.includes('name_device');
    const includesWaitOnline = selectedSteps.includes('wait_for_online');

    const needsLicensingDialog =
        includesLicensing &&
        selectedDevices.some(
            (device) => !device.license_tag || !device.license_type,
        );

    const apDevicesNeedingOptionalName = selectedDevices.filter(
        (device) =>
            includesNameDevice &&
            isApDevice(device.device_function) &&
            !deviceHasExplicitName(device.name, device.serial),
    );

    const stepOrderError = validateCustomWorkflowStepOrder(
        selectedSteps,
        labelsByKey,
    );

    const availableToAdd = availableSteps.filter(
        (step) => !selectedSteps.includes(step.step_key),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: current_client?.name ?? 'Clients', href: clientIndex().url },
        { title: deployment.name, href: showDeployment(deployment.id).url },
        {
            title: 'Custom workflow',
            href: customProvisionDeployment(deployment.id).url,
        },
    ];

    const toggleDevice = (deviceId: number) => {
        setSelectedDeviceIds((prev) =>
            prev.includes(deviceId)
                ? prev.filter((id) => id !== deviceId)
                : [...prev, deviceId],
        );
    };

    const addStep = (stepKey: string) => {
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

    const loadTemplate = (templateId: string) => {
        setSelectedTemplateId(templateId);
        if (!templateId) {
            return;
        }
        const template = templates.find((row) => String(row.id) === templateId);
        if (!template) {
            return;
        }
        setSelectedSteps([...template.steps]);
        setWorkflowName(template.name);
        setTemplateName(template.name);
    };

    const buildDevicesPayload = (): Array<Record<string, string | number>> => {
        const byId = new Map<number, Record<string, string | number>>();

        if (needsLicensingDialog && licensingMode === 'per_device') {
            selectedDevices
                .filter((d) => !d.license_tag || !d.license_type)
                .forEach((device) => {
                    byId.set(device.id, {
                        id: device.id,
                        license_tag: perDeviceLicense[device.id]?.license_tag ?? '',
                        license_type:
                            perDeviceLicense[device.id]?.license_type ?? '',
                    });
                });
        }

        if (apDevicesNeedingOptionalName.length > 0) {
            apDevicesNeedingOptionalName.forEach((device) => {
                const existing = byId.get(device.id) ?? { id: device.id };
                existing.name = perDeviceApNames[device.id] ?? '';
                byId.set(device.id, existing);
            });
        }

        return Array.from(byId.values());
    };

    const buildWorkflowPayload = (): Record<string, unknown> => {
        const payload: Record<string, unknown> = {
            device_ids: selectedDeviceIds,
            deployment_time: deploymentTimeHours * 60 + deploymentTimeMinutes,
            wait_time: waitTimeMinutes,
            online_detection_mode: onlineDetectionMode,
            licensing_mode: licensingMode,
            steps: selectedSteps,
            name: workflowName || undefined,
            save_as_template: saveAsTemplate,
            template_name: saveAsTemplate
                ? templateName || workflowName
                : undefined,
            template_id: selectedTemplateId
                ? Number(selectedTemplateId)
                : undefined,
        };

        if (needsLicensingDialog && licensingMode === 'uniform') {
            payload.license_tag = uniformLicenseTag;
            payload.license_type = uniformLicenseType;
        }

        const devicesPayload = buildDevicesPayload();
        if (devicesPayload.length > 0) {
            payload.devices = devicesPayload;
        }

        if (preflightResult) {
            const results: Record<
                string,
                Record<string, { status: string; message: string }>
            > = {};
            for (const device of preflightResult.devices) {
                results[String(device.device_id)] = {};
                for (const step of device.steps) {
                    results[String(device.device_id)][step.step_key] = {
                        status: step.status,
                        message: step.message,
                    };
                }
            }
            payload.preflight_results = results;
        }

        return payload;
    };

    const runPreflight = async (): Promise<PreflightPayload | null> => {
        setPreflightLoading(true);
        try {
            const devicesPayload = buildDevicesPayload();
            const response = await fetch(preflightProvision(deployment.id).url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    device_ids: selectedDeviceIds,
                    steps: selectedSteps,
                    devices:
                        devicesPayload.length > 0 ? devicesPayload : undefined,
                }),
            });

            if (!response.ok) {
                const errorBody = await response.json().catch(() => null);
                const message =
                    errorBody?.message ??
                    Object.values(errorBody?.errors ?? {})
                        .flat()
                        .find((value) => typeof value === 'string') ??
                    'Preflight checks failed.';
                toast.error(String(message));
                return null;
            }

            const payload = (await response.json()) as PreflightPayload;
            setPreflightResult(payload);
            setPreflightOpen(true);
            return payload;
        } catch {
            toast.error('Preflight checks failed.');
            return null;
        } finally {
            setPreflightLoading(false);
        }
    };

    const submitWorkflow = () => {
        if (stepOrderError) {
            toast.error(stepOrderError);
            return;
        }
        if (selectedDeviceIds.length === 0) {
            toast.error('Select at least one device.');
            return;
        }
        if (saveAsTemplate && !(templateName || workflowName).trim()) {
            toast.error('Enter a template name to save this workflow.');
            return;
        }

        setSubmitting(true);
        router.post(storeProvision(deployment.id).url, buildWorkflowPayload() as never, {
            onFinish: () => {
                setSubmitting(false);
                setPreflightOpen(false);
            },
        });
    };

    const startWorkflow = async () => {
        if (stepOrderError) {
            toast.error(stepOrderError);
            return;
        }
        await runPreflight();
    };

    const deleteSelectedTemplate = () => {
        if (!selectedTemplateId || !current_client?.id) {
            return;
        }
        router.delete(destroyTemplate(Number(selectedTemplateId)).url, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedTemplateId('');
                toast.success('Template deleted.');
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Create custom workflow
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Choose steps in order (licensing → preprovision →
                            site/group → anything else), name the run, and
                            optionally save it as a reusable template.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={showDeployment(deployment.id).url}>
                                Back to deployment
                            </Link>
                        </Button>
                        {workflow && !workflow.is_terminal ? (
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.post(cancelWorkflow(workflow.id).url)
                                }
                            >
                                Cancel workflow
                            </Button>
                        ) : null}
                        <Button
                            onClick={() => void startWorkflow()}
                            disabled={
                                selectedDeviceIds.length === 0 ||
                                selectedSteps.length === 0 ||
                                Boolean(stepOrderError) ||
                                preflightLoading ||
                                submitting
                            }
                            data-test="start-custom-workflow"
                        >
                            {preflightLoading ? (
                                <Loader2 className="mr-2 size-4 animate-spin" />
                            ) : (
                                <Play className="mr-2 size-4" />
                            )}
                            Run workflow
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Workflow definition
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">
                                    Run name
                                </label>
                                <Input
                                    value={workflowName}
                                    onChange={(e) =>
                                        setWorkflowName(e.target.value)
                                    }
                                    placeholder="Optional name for this run"
                                    data-test="custom-workflow-name"
                                />
                            </div>

                            <div className="space-y-2">
                                <label className="text-sm font-medium">
                                    Load template
                                </label>
                                <div className="flex gap-2">
                                    <select
                                        className={selectClassName}
                                        value={selectedTemplateId}
                                        onChange={(e) =>
                                            loadTemplate(e.target.value)
                                        }
                                        data-test="load-workflow-template"
                                    >
                                        <option value="">
                                            Select a saved template…
                                        </option>
                                        {templates.map((template) => (
                                            <option
                                                key={template.id}
                                                value={template.id}
                                            >
                                                {template.name}
                                            </option>
                                        ))}
                                    </select>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        disabled={!selectedTemplateId}
                                        onClick={deleteSelectedTemplate}
                                        aria-label="Delete template"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <div className="space-y-2 rounded-md border p-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={saveAsTemplate}
                                        onChange={(e) =>
                                            setSaveAsTemplate(e.target.checked)
                                        }
                                        data-test="save-as-template"
                                    />
                                    Save as reusable template
                                </label>
                                {saveAsTemplate ? (
                                    <Input
                                        value={templateName}
                                        onChange={(e) =>
                                            setTemplateName(e.target.value)
                                        }
                                        placeholder="Template name"
                                        data-test="template-name"
                                    />
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-medium">
                                        Selected steps
                                    </p>
                                    <select
                                        className={cn(selectClassName, 'max-w-xs')}
                                        value=""
                                        onChange={(e) => {
                                            if (e.target.value) {
                                                addStep(e.target.value);
                                            }
                                        }}
                                        data-test="add-workflow-step"
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
                                        data-test="step-order-error"
                                    >
                                        {stepOrderError}
                                    </p>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Order must respect licensing →
                                        preprovision → site/group before other
                                        steps. Free steps can be rearranged.
                                    </p>
                                )}
                                <div
                                    className="space-y-2"
                                    data-test="selected-workflow-steps"
                                >
                                    {selectedSteps.length === 0 ? (
                                        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                            No steps yet. Add from the catalogue.
                                        </p>
                                    ) : (
                                        selectedSteps.map((stepKey, index) => (
                                            <div
                                                key={`${stepKey}-${index}`}
                                                className="flex items-center gap-2 rounded-md border px-2 py-1.5"
                                            >
                                                <Workflow className="size-4 shrink-0 text-muted-foreground" />
                                                <span className="flex-1 text-sm">
                                                    {index + 1}.{' '}
                                                    {labelsByKey[stepKey] ??
                                                        stepKey}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        moveStep(index, -1)
                                                    }
                                                    disabled={index === 0}
                                                    aria-label="Move up"
                                                >
                                                    <ArrowUp className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        moveStep(index, 1)
                                                    }
                                                    disabled={
                                                        index ===
                                                        selectedSteps.length - 1
                                                    }
                                                    aria-label="Move down"
                                                >
                                                    <ArrowDown className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        removeStep(index)
                                                    }
                                                    aria-label="Remove step"
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            {availableToAdd.length > 0 ? (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">
                                        Catalogue
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {availableToAdd.map((step) => (
                                            <Button
                                                key={step.step_key}
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    addStep(step.step_key)
                                                }
                                            >
                                                <Plus className="mr-1 size-3" />
                                                {step.label}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Devices ({selectedDeviceIds.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-2 flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setSelectedDeviceIds(
                                                deployment.devices.map(
                                                    (d) => d.id,
                                                ),
                                            )
                                        }
                                    >
                                        Select all
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setSelectedDeviceIds([])}
                                    >
                                        Clear
                                    </Button>
                                </div>
                                <div className="max-h-64 space-y-1 overflow-y-auto rounded-md border p-2">
                                    {deployment.devices.map((device) => (
                                        <label
                                            key={device.id}
                                            className="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted/50"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selectedDeviceIds.includes(
                                                    device.id,
                                                )}
                                                onChange={() =>
                                                    toggleDevice(device.id)
                                                }
                                            />
                                            <span className="truncate">
                                                {formatDeviceLabel(
                                                    device.name,
                                                    device.serial,
                                                )}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Run options
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-3 gap-3">
                                    <div>
                                        <p className="mb-1 text-xs text-muted-foreground">
                                            Deploy hours
                                        </p>
                                        <Input
                                            type="number"
                                            min={0}
                                            value={deploymentTimeHours}
                                            onChange={(e) =>
                                                setDeploymentTimeHours(
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <p className="mb-1 text-xs text-muted-foreground">
                                            Deploy minutes
                                        </p>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={59}
                                            value={deploymentTimeMinutes}
                                            onChange={(e) =>
                                                setDeploymentTimeMinutes(
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <p className="mb-1 text-xs text-muted-foreground">
                                            Wait minutes
                                        </p>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={waitTimeMinutes}
                                            onChange={(e) =>
                                                setWaitTimeMinutes(
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                {includesWaitOnline ? (
                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">
                                            Online detection
                                        </p>
                                        <select
                                            className={selectClassName}
                                            value={onlineDetectionMode}
                                            onChange={(e) =>
                                                setOnlineDetectionMode(
                                                    e.target.value as
                                                        | 'poll'
                                                        | 'webhook'
                                                        | 'stream',
                                                )
                                            }
                                        >
                                            <option value="poll">Poll</option>
                                            <option
                                                value="webhook"
                                                disabled={
                                                    !hasClassicWebhookSecret
                                                }
                                            >
                                                Webhook
                                            </option>
                                            <option
                                                value="stream"
                                                disabled={
                                                    !hasClassicStreamingCredentials
                                                }
                                            >
                                                Stream
                                            </option>
                                        </select>
                                    </div>
                                ) : null}

                                {needsLicensingDialog ? (
                                    <div className="space-y-3 rounded-md border p-3">
                                        <p className="text-sm font-medium">
                                            Licensing
                                        </p>
                                        {licensing_error ? (
                                            <p className="text-sm text-destructive">
                                                {licensing_error}
                                            </p>
                                        ) : null}
                                        <div className="flex gap-4 text-sm">
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    checked={
                                                        licensingMode ===
                                                        'uniform'
                                                    }
                                                    onChange={() =>
                                                        setLicensingMode(
                                                            'uniform',
                                                        )
                                                    }
                                                />
                                                Uniform
                                            </label>
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    checked={
                                                        licensingMode ===
                                                        'per_device'
                                                    }
                                                    onChange={() =>
                                                        setLicensingMode(
                                                            'per_device',
                                                        )
                                                    }
                                                />
                                                Per device
                                            </label>
                                        </div>
                                        {licensingMode === 'uniform' ? (
                                            <div className="grid grid-cols-2 gap-3">
                                                <select
                                                    className={selectClassName}
                                                    value={uniformLicenseTag}
                                                    onChange={(e) =>
                                                        setUniformLicenseTag(
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select tag
                                                    </option>
                                                    {license_tags.map((tag) => (
                                                        <option
                                                            key={tag}
                                                            value={tag}
                                                        >
                                                            {tag}
                                                        </option>
                                                    ))}
                                                </select>
                                                <select
                                                    className={selectClassName}
                                                    value={uniformLicenseType}
                                                    onChange={(e) =>
                                                        setUniformLicenseType(
                                                            e.target
                                                                .value as LicenseTypeOption | '',
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select type
                                                    </option>
                                                    {license_type_options.map(
                                                        (type) => (
                                                            <option
                                                                key={type}
                                                                value={type}
                                                            >
                                                                {type}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                        ) : (
                                            <div className="max-h-40 space-y-2 overflow-y-auto">
                                                {selectedDevices
                                                    .filter(
                                                        (d) =>
                                                            !d.license_tag ||
                                                            !d.license_type,
                                                    )
                                                    .map((device) => (
                                                        <div
                                                            key={device.id}
                                                            className="grid grid-cols-3 items-center gap-2"
                                                        >
                                                            <span className="truncate text-sm">
                                                                {device.name}
                                                            </span>
                                                            <select
                                                                className={
                                                                    selectClassName
                                                                }
                                                                value={
                                                                    perDeviceLicense[
                                                                        device
                                                                            .id
                                                                    ]
                                                                        ?.license_tag ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    e,
                                                                ) =>
                                                                    setPerDeviceLicense(
                                                                        (
                                                                            prev,
                                                                        ) => ({
                                                                            ...prev,
                                                                            [device.id]:
                                                                                {
                                                                                    ...prev[
                                                                                        device
                                                                                            .id
                                                                                    ],
                                                                                    license_tag:
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    license_type:
                                                                                        prev[
                                                                                            device
                                                                                                .id
                                                                                        ]
                                                                                            ?.license_type ??
                                                                                        '',
                                                                                },
                                                                        }),
                                                                    )
                                                                }
                                                            >
                                                                <option value="">
                                                                    Tag
                                                                </option>
                                                                {license_tags.map(
                                                                    (tag) => (
                                                                        <option
                                                                            key={
                                                                                tag
                                                                            }
                                                                            value={
                                                                                tag
                                                                            }
                                                                        >
                                                                            {
                                                                                tag
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                            <select
                                                                className={
                                                                    selectClassName
                                                                }
                                                                value={
                                                                    perDeviceLicense[
                                                                        device
                                                                            .id
                                                                    ]
                                                                        ?.license_type ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    e,
                                                                ) =>
                                                                    setPerDeviceLicense(
                                                                        (
                                                                            prev,
                                                                        ) => ({
                                                                            ...prev,
                                                                            [device.id]:
                                                                                {
                                                                                    license_tag:
                                                                                        prev[
                                                                                            device
                                                                                                .id
                                                                                        ]
                                                                                            ?.license_tag ??
                                                                                        '',
                                                                                    license_type:
                                                                                        e
                                                                                            .target
                                                                                            .value as
                                                                                            | LicenseTypeOption
                                                                                            | '',
                                                                                },
                                                                        }),
                                                                    )
                                                                }
                                                            >
                                                                <option value="">
                                                                    Type
                                                                </option>
                                                                {license_type_options.map(
                                                                    (type) => (
                                                                        <option
                                                                            key={
                                                                                type
                                                                            }
                                                                            value={
                                                                                type
                                                                            }
                                                                        >
                                                                            {
                                                                                type
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </div>
                                                    ))}
                                            </div>
                                        )}
                                    </div>
                                ) : null}

                                {apDevicesNeedingOptionalName.length > 0 ? (
                                    <div className="space-y-2 rounded-md border p-3">
                                        <p className="text-sm font-medium">
                                            AP naming (optional)
                                        </p>
                                        {apDevicesNeedingOptionalName.map(
                                            (device) => (
                                                <div
                                                    key={device.id}
                                                    className="grid grid-cols-2 gap-2"
                                                >
                                                    <span className="truncate text-sm text-muted-foreground">
                                                        {device.serial}
                                                    </span>
                                                    <Input
                                                        value={
                                                            perDeviceApNames[
                                                                device.id
                                                            ] ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            setPerDeviceApNames(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [device.id]:
                                                                        e.target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        placeholder="Hostname"
                                                    />
                                                </div>
                                            ),
                                        )}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {workflow?.steps ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {workflow.name
                                    ? `Latest run: ${workflow.name}`
                                    : 'Latest custom run'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Status: {workflow.status} · in progress{' '}
                                {workflow.summary.in_progress} · completed{' '}
                                {workflow.summary.completed} · failed{' '}
                                {workflow.summary.failed}
                            </p>
                            <div className="grid gap-3 md:grid-cols-2">
                                {workflow.devices.map((device) => (
                                    <div
                                        key={device.id}
                                        className="rounded-md border p-3"
                                    >
                                        <p className="text-sm font-medium">
                                            {formatDeviceLabel(
                                                device.name,
                                                device.serial,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {device.status_message}
                                        </p>
                                        <div className="mt-2 space-y-1">
                                            {device.steps.map((step) => (
                                                <div
                                                    key={step.step_key}
                                                    className="flex items-center gap-2 text-xs"
                                                >
                                                    <span
                                                        className={cn(
                                                            'size-2 rounded-full',
                                                            statusColor(
                                                                step.status,
                                                            ),
                                                        )}
                                                    />
                                                    <span>{step.label}</span>
                                                    <span className="uppercase text-muted-foreground">
                                                        {step.status}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}
            </div>

            <Dialog open={preflightOpen} onOpenChange={setPreflightOpen}>
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogTitle>Preflight results</DialogTitle>
                    <DialogDescription>
                        Checks for the steps in your custom workflow.
                    </DialogDescription>
                    {preflightResult?.has_warnings ? (
                        <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            One or more checks failed or were not verified. You
                            can continue anyway.
                        </div>
                    ) : (
                        <div className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100">
                            All checked steps look good.
                        </div>
                    )}
                    <div className="max-h-80 space-y-3 overflow-y-auto">
                        {preflightResult?.devices.map((device) => (
                            <div
                                key={device.device_id}
                                className="rounded-md border p-3"
                            >
                                <p className="text-sm font-medium">
                                    {formatDeviceLabel(
                                        device.name,
                                        device.serial,
                                    )}
                                </p>
                                <div className="mt-2 space-y-1">
                                    {device.steps.map((step) => (
                                        <div
                                            key={step.step_key}
                                            className="flex items-start gap-2 text-xs"
                                        >
                                            <span
                                                className={cn(
                                                    'mt-1 size-2 shrink-0 rounded-full',
                                                    statusColor(step.status),
                                                )}
                                            />
                                            <div>
                                                <span className="font-medium">
                                                    {step.label}
                                                </span>
                                                <span className="ml-2 uppercase text-muted-foreground">
                                                    {step.status}
                                                </span>
                                                <p className="text-muted-foreground">
                                                    {step.message}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPreflightOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitWorkflow}
                            disabled={submitting}
                            data-test="continue-custom-after-preflight"
                        >
                            {preflightResult?.has_warnings
                                ? 'Continue anyway'
                                : 'Start'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
