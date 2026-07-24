import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Loader2, Play, RotateCcw } from 'lucide-react';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import {
    deviceHasExplicitName,
    formatDeviceLabel,
    isApDevice,
} from '@/lib/device-label';
import type { LicenseTypeOption } from '@/lib/license-types';
import { poolAvailableSeats } from '@/lib/licensing-pool';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import {
    provision as provisionDeployment,
    show as showDeployment,
} from '@/routes/deployments';
import {
    preflight as preflightProvision,
    store as storeProvision,
} from '@/routes/deployments/provision';
import { cancel as cancelWorkflow } from '@/routes/provisioning_workflows';
import { restart as restartWorkflowDevice } from '@/routes/provisioning_workflow_devices';
import { store as storeTask } from '@/routes/tasks';
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
    restartable_steps: Array<{ step_key: string; label: string }>;
};

type WorkflowPayload = {
    id: number;
    status: string;
    deployment_time: number;
    wait_time: number;
    online_detection_mode: 'poll' | 'webhook' | 'stream';
    started_at: string | null;
    completed_at: string | null;
    summary: { in_progress: number; completed: number; failed: number };
    licensing_failures: Array<{
        device_id: number;
        name: string;
        serial: string;
        message: string | null;
    }>;
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

type PreflightRemediation = {
    step_key: string;
    task_type: string;
    label: string;
    device_ids: number[];
    options: string[];
};

type PreflightPayload = {
    has_warnings: boolean;
    devices: PreflightDeviceResult[];
    remediations: PreflightRemediation[];
};

type ProvisionPageProps = SharedData & {
    deployment: {
        id: number;
        name: string;
        devices: DeploymentDevice[];
    };
    workflow: WorkflowPayload | null;
    available_steps: AvailableStep[];
    license_tags: string[];
    available_subscriptions: AvailableSubscription[];
    license_type_options: LicenseTypeOption[];
    licensing_synced_at: string | null;
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

export default function Provision() {
    const {
        current_client,
        deployment,
        workflow,
        available_steps: availableStepsProp = [],
        license_tags,
        available_subscriptions = [],
        license_type_options,
        licensing_error,
        flash,
        selected_device_ids: selectedDeviceIdsProp = [],
        has_classic_webhook_secret: hasClassicWebhookSecret = false,
        has_classic_streaming_credentials: hasClassicStreamingCredentials = false,
    } = usePage<ProvisionPageProps>().props;

    const availableSteps = availableStepsProp.length > 0
        ? availableStepsProp
        : [{ step_key: 'verify_licensing', label: 'Verify licensing', order: 1 }];

    const [selectedDeviceIds] = useState<number[]>(
        selectedDeviceIdsProp.length > 0
            ? selectedDeviceIdsProp
            : deployment.devices.map((d) => d.id),
    );
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0);
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(10);
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(1);
    const [onlineDetectionMode, setOnlineDetectionMode] = useState<'poll' | 'webhook' | 'stream'>('poll');
    const [licensingMode, setLicensingMode] = useState<'uniform' | 'per_device'>('uniform');
    const [uniformLicenseTag, setUniformLicenseTag] = useState('');
    const [uniformLicenseType, setUniformLicenseType] = useState<LicenseTypeOption | ''>('');
    const [perDeviceLicense, setPerDeviceLicense] = useState<
        Record<number, { license_tag: string; license_type: LicenseTypeOption | '' }>
    >({});
    const [perDeviceApNames, setPerDeviceApNames] = useState<Record<number, string>>({});
    const [startStep, setStartStep] = useState(availableSteps[0]?.step_key ?? 'verify_licensing');
    const [omitSteps, setOmitSteps] = useState<string[]>([]);
    const [checkSkippedSteps, setCheckSkippedSteps] = useState(false);
    const [startDialogOpen, setStartDialogOpen] = useState(false);
    const [preflightOpen, setPreflightOpen] = useState(false);
    const [preflightLoading, setPreflightLoading] = useState(false);
    const [preflightResult, setPreflightResult] = useState<PreflightPayload | null>(null);
    const [activeRemediation, setActiveRemediation] = useState<PreflightRemediation | null>(null);
    const [remediationLicensingMode, setRemediationLicensingMode] = useState<'uniform' | 'per_device'>('uniform');
    const [remediationLicenseTag, setRemediationLicenseTag] = useState('');
    const [remediationLicenseType, setRemediationLicenseType] = useState<LicenseTypeOption | ''>('');
    const [remediationPerDeviceLicense, setRemediationPerDeviceLicense] = useState<
        Record<number, { license_tag: string; license_type: LicenseTypeOption | '' }>
    >({});
    const [remediationLaunching, setRemediationLaunching] = useState(false);

    const shouldPoll = workflow !== null && !workflow.is_terminal;
    usePoll(shouldPoll ? 2000 : 0);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    const selectedDevices = useMemo(
        () => deployment.devices.filter((d) => selectedDeviceIds.includes(d.id)),
        [deployment.devices, selectedDeviceIds],
    );

    const startStepMeta = availableSteps.find((step) => step.step_key === startStep) ?? availableSteps[0];
    const startStepOrder = startStepMeta?.order ?? 1;
    const canCheckSkipped = startStepOrder > (availableSteps[0]?.order ?? 1);
    const omittableSteps = availableSteps.filter((step) => step.order > startStepOrder);

    const needsLicensingDialog = selectedDevices.some(
        (d) => !d.license_tag || !d.license_type,
    ) && startStepOrder <= 1 && !omitSteps.includes('verify_licensing');

    const apDevicesNeedingOptionalName = useMemo(
        () =>
            selectedDevices.filter(
                (d) =>
                    isApDevice(d.device_function) &&
                    !deviceHasExplicitName(d.name, d.serial),
            ),
        [selectedDevices],
    );

    const needsApNamingDialog = apDevicesNeedingOptionalName.length > 0;

    const buildDevicesPayload = (): Array<Record<string, string | number>> => {
        const byId = new Map<number, Record<string, string | number>>();

        if (needsLicensingDialog && licensingMode === 'per_device') {
            selectedDevices
                .filter((d) => !d.license_tag || !d.license_type)
                .forEach((device) => {
                    byId.set(device.id, {
                        id: device.id,
                        license_tag: perDeviceLicense[device.id]?.license_tag ?? '',
                        license_type: perDeviceLicense[device.id]?.license_type ?? '',
                    });
                });
        }

        if (needsApNamingDialog) {
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
            start_step: startStep,
            omit_steps: omitSteps,
        };

        if (needsLicensingDialog) {
            if (licensingMode === 'uniform') {
                payload.license_tag = uniformLicenseTag;
                payload.license_type = uniformLicenseType;
            }
        }

        const devicesPayload = buildDevicesPayload();
        if (devicesPayload.length > 0) {
            payload.devices = devicesPayload;
        }

        if (preflightResult) {
            const results: Record<string, Record<string, { status: string; message: string }>> = {};
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

    const breadcrumbs: BreadcrumbItem[] = [
        { title: current_client?.name ?? 'Clients', href: clientIndex().url },
        { title: deployment.name, href: showDeployment(deployment.id).url },
        { title: 'Provision', href: provisionDeployment(deployment.id).url },
    ];

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
                    start_step: startStep,
                    devices: devicesPayload.length > 0 ? devicesPayload : undefined,
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
        router.post(storeProvision(deployment.id).url, buildWorkflowPayload() as never, {
            onSuccess: () => {
                setStartDialogOpen(false);
                setPreflightOpen(false);
                setActiveRemediation(null);
            },
        });
    };

    const startWorkflow = async () => {
        if (checkSkippedSteps && canCheckSkipped) {
            await runPreflight();
            return;
        }

        submitWorkflow();
    };

    const toggleOmitStep = (stepKey: string) => {
        setOmitSteps((prev) =>
            prev.includes(stepKey) ? prev.filter((key) => key !== stepKey) : [...prev, stepKey],
        );
    };

    const onStartStepChange = (nextStart: string) => {
        setStartStep(nextStart);
        const nextOrder = availableSteps.find((step) => step.step_key === nextStart)?.order ?? 1;
        setOmitSteps((prev) =>
            prev.filter((key) => {
                const order = availableSteps.find((step) => step.step_key === key)?.order ?? 0;
                return order > nextOrder;
            }),
        );
        if (nextOrder <= (availableSteps[0]?.order ?? 1)) {
            setCheckSkippedSteps(false);
        }
    };

    const launchRemediation = () => {
        if (!activeRemediation) {
            return;
        }

        const devicesForTask = deployment.devices.filter((device) =>
            activeRemediation.device_ids.includes(device.id),
        );
        if (devicesForTask.length === 0) {
            toast.error('No devices selected for remediation.');
            return;
        }

        const needsLicensing = activeRemediation.options.includes('licensing');
        if (needsLicensing) {
            if (remediationLicensingMode === 'uniform') {
                if (!remediationLicenseTag || !remediationLicenseType) {
                    toast.error('Select a license tag and type.');
                    return;
                }
                const seats = poolAvailableSeats(
                    available_subscriptions,
                    remediationLicenseTag,
                    remediationLicenseType,
                );
                if (devicesForTask.length > seats) {
                    toast.error(
                        `Only ${seats} ${remediationLicenseType} seat(s) available for tag "${remediationLicenseTag}".`,
                    );
                    return;
                }
            } else {
                const missing = devicesForTask.some((device) => {
                    const selection = remediationPerDeviceLicense[device.id];
                    return !selection?.license_tag || !selection?.license_type;
                });
                if (missing) {
                    toast.error('Select a license tag and type for each device.');
                    return;
                }
            }
        }

        const devicePayload = devicesForTask.map((device) => {
            if (needsLicensing && remediationLicensingMode === 'per_device') {
                const selection = remediationPerDeviceLicense[device.id];
                return {
                    id: device.id,
                    license_tag: selection?.license_tag ?? '',
                    license_type: selection?.license_type ?? '',
                };
            }
            return { id: device.id };
        });

        setRemediationLaunching(true);
        router.post(
            storeTask(deployment.id).url,
            {
                task_type: activeRemediation.task_type,
                devices: devicePayload,
                deployment_time: deploymentTimeHours * 60 + deploymentTimeMinutes,
                wait_time: waitTimeMinutes,
                licensing_mode: needsLicensing ? remediationLicensingMode : undefined,
                license_tag:
                    needsLicensing && remediationLicensingMode === 'uniform'
                        ? remediationLicenseTag
                        : undefined,
                license_type:
                    needsLicensing && remediationLicensingMode === 'uniform'
                        ? remediationLicenseType
                        : undefined,
            } as never,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Started ${activeRemediation.task_type} for ${devicesForTask.length} device(s).`);
                    setActiveRemediation(null);
                    setRemediationLaunching(false);
                },
                onError: (errors) => {
                    setRemediationLaunching(false);
                    const message = Object.values(errors)
                        .flat()
                        .find((value) => typeof value === 'string' && value.trim() !== '');
                    toast.error(message ? String(message) : 'Failed to launch remediation task.');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Device Provisioning Workflow</h1>
                        <p className="text-sm text-muted-foreground">
                            Run devices through licensing, preprovision, online detection, and full configuration.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={showDeployment(deployment.id).url}>Back to deployment</Link>
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
                        <Dialog open={startDialogOpen} onOpenChange={setStartDialogOpen}>
                            <DialogTrigger asChild>
                                <Button disabled={selectedDeviceIds.length === 0}>
                                    <Play className="mr-2 size-4" />
                                    Start workflow
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                <DialogTitle>Start provisioning workflow</DialogTitle>
                                <DialogDescription>
                                    {selectedDeviceIds.length} device(s) selected.
                                </DialogDescription>
                                <div className="space-y-4 py-2">
                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">Start at step</p>
                                        <select
                                            className={selectClassName}
                                            value={startStep}
                                            onChange={(e) => onStartStepChange(e.target.value)}
                                            data-test="start-step"
                                        >
                                            {availableSteps.map((step) => (
                                                <option key={step.step_key} value={step.step_key}>
                                                    {step.order}. {step.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {omittableSteps.length > 0 ? (
                                        <div className="space-y-2 rounded-md border p-3">
                                            <p className="text-sm font-medium">Omit steps</p>
                                            <p className="text-xs text-muted-foreground">
                                                Steps after the start step can be skipped for this run.
                                            </p>
                                            <div className="max-h-40 space-y-1 overflow-y-auto">
                                                {omittableSteps.map((step) => (
                                                    <label
                                                        key={step.step_key}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={omitSteps.includes(step.step_key)}
                                                            onChange={() => toggleOmitStep(step.step_key)}
                                                        />
                                                        {step.label}
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}

                                    <label
                                        className={cn(
                                            'flex items-start gap-2 text-sm',
                                            !canCheckSkipped && 'opacity-50',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            checked={checkSkippedSteps}
                                            disabled={!canCheckSkipped}
                                            onChange={(e) => setCheckSkippedSteps(e.target.checked)}
                                            data-test="check-skipped-steps"
                                        />
                                        <span>
                                            Check prerequisites for skipped steps before starting.
                                            <span className="block text-xs text-muted-foreground">
                                                Failures are warnings only; you can continue anyway or fix with a task.
                                            </span>
                                        </span>
                                    </label>

                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">Online detection</p>
                                        <div className="flex flex-wrap gap-4 text-sm">
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    checked={onlineDetectionMode === 'poll'}
                                                    onChange={() => setOnlineDetectionMode('poll')}
                                                />
                                                Poll Central
                                            </label>
                                            <label
                                                className={cn(
                                                    'flex items-center gap-2',
                                                    !hasClassicWebhookSecret && 'opacity-50',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    checked={onlineDetectionMode === 'webhook'}
                                                    disabled={!hasClassicWebhookSecret}
                                                    onChange={() => setOnlineDetectionMode('webhook')}
                                                />
                                                Webhook
                                            </label>
                                            <label
                                                className={cn(
                                                    'flex items-center gap-2',
                                                    !hasClassicStreamingCredentials && 'opacity-50',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    checked={onlineDetectionMode === 'stream'}
                                                    disabled={!hasClassicStreamingCredentials}
                                                    onChange={() => setOnlineDetectionMode('stream')}
                                                />
                                                Streaming API
                                            </label>
                                        </div>
                                    </div>
                                    <div
                                        className={cn(
                                            'grid gap-3',
                                            onlineDetectionMode === 'poll' ? 'grid-cols-3' : 'grid-cols-2',
                                        )}
                                    >
                                        <label className="text-sm">
                                            Hours
                                            <input
                                                type="number"
                                                min={0}
                                                className={selectClassName}
                                                value={deploymentTimeHours}
                                                onChange={(e) =>
                                                    setDeploymentTimeHours(Number(e.target.value))
                                                }
                                            />
                                        </label>
                                        <label className="text-sm">
                                            Minutes
                                            <input
                                                type="number"
                                                min={1}
                                                className={selectClassName}
                                                value={deploymentTimeMinutes}
                                                onChange={(e) =>
                                                    setDeploymentTimeMinutes(Number(e.target.value))
                                                }
                                            />
                                        </label>
                                        {onlineDetectionMode === 'poll' ? (
                                            <label className="text-sm">
                                                Wait (min)
                                                <input
                                                    type="number"
                                                    min={1}
                                                    className={selectClassName}
                                                    value={waitTimeMinutes}
                                                    onChange={(e) =>
                                                        setWaitTimeMinutes(Number(e.target.value))
                                                    }
                                                />
                                            </label>
                                        ) : null}
                                    </div>
                                    {needsLicensingDialog ? (
                                        <div className="space-y-3 rounded-md border p-3">
                                            <p className="text-sm font-medium">
                                                Licensing (required for devices without CSV license columns)
                                            </p>
                                            {licensing_error ? (
                                                <p className="text-sm text-destructive">{licensing_error}</p>
                                            ) : null}
                                            <div className="flex gap-4 text-sm">
                                                <label className="flex items-center gap-2">
                                                    <input
                                                        type="radio"
                                                        checked={licensingMode === 'uniform'}
                                                        onChange={() => setLicensingMode('uniform')}
                                                    />
                                                    Uniform tag/type
                                                </label>
                                                <label className="flex items-center gap-2">
                                                    <input
                                                        type="radio"
                                                        checked={licensingMode === 'per_device'}
                                                        onChange={() => setLicensingMode('per_device')}
                                                    />
                                                    Per device
                                                </label>
                                            </div>
                                            {licensingMode === 'uniform' ? (
                                                <div className="grid grid-cols-2 gap-3">
                                                    <select
                                                        className={selectClassName}
                                                        value={uniformLicenseTag}
                                                        onChange={(e) => setUniformLicenseTag(e.target.value)}
                                                    >
                                                        <option value="">Select tag</option>
                                                        {license_tags.map((tag) => (
                                                            <option key={tag} value={tag}>
                                                                {tag}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <select
                                                        className={selectClassName}
                                                        value={uniformLicenseType}
                                                        onChange={(e) =>
                                                            setUniformLicenseType(
                                                                e.target.value as LicenseTypeOption | '',
                                                            )
                                                        }
                                                    >
                                                        <option value="">Select type</option>
                                                        {license_type_options.map((type) => (
                                                            <option key={type} value={type}>
                                                                {type}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                            ) : (
                                                <div className="max-h-48 space-y-2 overflow-y-auto">
                                                    {selectedDevices
                                                        .filter((d) => !d.license_tag || !d.license_type)
                                                        .map((device) => (
                                                            <div
                                                                key={device.id}
                                                                className="grid grid-cols-3 items-center gap-2"
                                                            >
                                                                <span className="truncate text-sm">
                                                                    {device.name}
                                                                </span>
                                                                <select
                                                                    className={selectClassName}
                                                                    value={
                                                                        perDeviceLicense[device.id]?.license_tag ?? ''
                                                                    }
                                                                    onChange={(e) =>
                                                                        setPerDeviceLicense((prev) => ({
                                                                            ...prev,
                                                                            [device.id]: {
                                                                                ...prev[device.id],
                                                                                license_tag: e.target.value,
                                                                                license_type:
                                                                                    prev[device.id]?.license_type ?? '',
                                                                            },
                                                                        }))
                                                                    }
                                                                >
                                                                    <option value="">Tag</option>
                                                                    {license_tags.map((tag) => (
                                                                        <option key={tag} value={tag}>
                                                                            {tag}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                <select
                                                                    className={selectClassName}
                                                                    value={
                                                                        perDeviceLicense[device.id]?.license_type ?? ''
                                                                    }
                                                                    onChange={(e) =>
                                                                        setPerDeviceLicense((prev) => ({
                                                                            ...prev,
                                                                            [device.id]: {
                                                                                license_tag:
                                                                                    prev[device.id]?.license_tag ?? '',
                                                                                license_type: e.target.value as
                                                                                    | LicenseTypeOption
                                                                                    | '',
                                                                            },
                                                                        }))
                                                                    }
                                                                >
                                                                    <option value="">Type</option>
                                                                    {license_type_options.map((type) => (
                                                                        <option key={type} value={type}>
                                                                            {type}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                        ))}
                                                </div>
                                            )}
                                        </div>
                                    ) : null}
                                    {needsApNamingDialog ? (
                                        <div className="space-y-3 rounded-md border p-3">
                                            <p className="text-sm font-medium">
                                                Device naming (optional for APs)
                                            </p>
                                            <div className="max-h-48 space-y-2 overflow-y-auto">
                                                {apDevicesNeedingOptionalName.map((device) => (
                                                    <div
                                                        key={device.id}
                                                        className="grid grid-cols-2 items-center gap-2"
                                                    >
                                                        <span className="truncate text-sm text-muted-foreground">
                                                            {device.serial}
                                                        </span>
                                                        <input
                                                            type="text"
                                                            className={selectClassName}
                                                            placeholder="Optional hostname"
                                                            value={perDeviceApNames[device.id] ?? ''}
                                                            onChange={(e) =>
                                                                setPerDeviceApNames((prev) => ({
                                                                    ...prev,
                                                                    [device.id]: e.target.value,
                                                                }))
                                                            }
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                                <DialogFooter>
                                    <Button
                                        onClick={() => void startWorkflow()}
                                        disabled={preflightLoading}
                                        data-test="start-workflow-submit"
                                    >
                                        {preflightLoading ? (
                                            <>
                                                <Loader2 className="mr-2 size-4 animate-spin" />
                                                Checking…
                                            </>
                                        ) : checkSkippedSteps && canCheckSkipped ? (
                                            'Run checks'
                                        ) : (
                                            'Start'
                                        )}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <Dialog
                    open={preflightOpen}
                    onOpenChange={(open) => {
                        setPreflightOpen(open);
                        if (!open) {
                            setActiveRemediation(null);
                        }
                    }}
                >
                    <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                        <DialogTitle>Preflight results</DialogTitle>
                        <DialogDescription>
                            Warnings mean the remainder of the workflow may fail if you continue.
                        </DialogDescription>
                        {preflightResult?.has_warnings ? (
                            <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                                One or more prerequisite checks failed or were not verified. You can fix
                                failures with a task, re-run checks, or continue anyway.
                            </div>
                        ) : (
                            <div className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100">
                                All checked prerequisites look good.
                            </div>
                        )}

                        {preflightResult?.remediations && preflightResult.remediations.length > 0 ? (
                            <div className="space-y-2">
                                <p className="text-sm font-medium">Fix failures</p>
                                <div className="flex flex-wrap gap-2">
                                    {preflightResult.remediations.map((remediation) => (
                                        <Button
                                            key={`${remediation.task_type}-${remediation.step_key}`}
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setActiveRemediation(remediation);
                                                setRemediationLicensingMode('uniform');
                                                setRemediationLicenseTag('');
                                                setRemediationLicenseType('');
                                                setRemediationPerDeviceLicense({});
                                            }}
                                            data-test={`remediate-${remediation.task_type}`}
                                        >
                                            {remediation.label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        <div className="max-h-80 space-y-3 overflow-y-auto">
                            {preflightResult?.devices.map((device) => (
                                <div key={device.device_id} className="rounded-md border p-3">
                                    <p className="text-sm font-medium">
                                        {formatDeviceLabel(device.name, device.serial)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">{device.serial}</p>
                                    <div className="mt-2 space-y-1">
                                        {device.steps.map((step) => (
                                            <div key={step.step_key} className="flex items-start gap-2 text-xs">
                                                <span
                                                    className={cn(
                                                        'mt-1 size-2 shrink-0 rounded-full',
                                                        statusColor(step.status),
                                                    )}
                                                />
                                                <div>
                                                    <span className="font-medium">{step.label}</span>
                                                    <span className="ml-2 uppercase text-muted-foreground">
                                                        {step.status}
                                                    </span>
                                                    <p className="text-muted-foreground">{step.message}</p>
                                                </div>
                                            </div>
                                        ))}
                                        {device.steps.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">
                                                No skipped steps to check for this device.
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <DialogFooter className="flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setPreflightOpen(false);
                                    setActiveRemediation(null);
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="secondary"
                                disabled={preflightLoading}
                                onClick={() => void runPreflight()}
                            >
                                {preflightLoading ? 'Checking…' : 'Re-run checks'}
                            </Button>
                            <Button
                                onClick={submitWorkflow}
                                data-test="continue-after-preflight"
                            >
                                {preflightResult?.has_warnings ? 'Continue anyway' : 'Continue'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog
                    open={activeRemediation !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setActiveRemediation(null);
                        }
                    }}
                >
                    <DialogContent className="max-w-lg">
                        <DialogTitle>{activeRemediation?.label ?? 'Fix failure'}</DialogTitle>
                        <DialogDescription>
                            Launches {activeRemediation?.task_type} for{' '}
                            {activeRemediation?.device_ids.length ?? 0} failed device(s) only.
                        </DialogDescription>
                        <div className="space-y-3 py-2">
                            <div className="grid grid-cols-3 gap-3">
                                <label className="text-sm">
                                    Hours
                                    <input
                                        type="number"
                                        min={0}
                                        className={selectClassName}
                                        value={deploymentTimeHours}
                                        onChange={(e) => setDeploymentTimeHours(Number(e.target.value))}
                                    />
                                </label>
                                <label className="text-sm">
                                    Minutes
                                    <input
                                        type="number"
                                        min={1}
                                        className={selectClassName}
                                        value={deploymentTimeMinutes}
                                        onChange={(e) => setDeploymentTimeMinutes(Number(e.target.value))}
                                    />
                                </label>
                                <label className="text-sm">
                                    Wait (min)
                                    <input
                                        type="number"
                                        min={1}
                                        className={selectClassName}
                                        value={waitTimeMinutes}
                                        onChange={(e) => setWaitTimeMinutes(Number(e.target.value))}
                                    />
                                </label>
                            </div>

                            {activeRemediation?.options.includes('licensing') ? (
                                <div className="space-y-3 rounded-md border p-3">
                                    <p className="text-sm font-medium">License assignment</p>
                                    <div className="flex gap-4 text-sm">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                checked={remediationLicensingMode === 'uniform'}
                                                onChange={() => setRemediationLicensingMode('uniform')}
                                            />
                                            Uniform
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                checked={remediationLicensingMode === 'per_device'}
                                                onChange={() => setRemediationLicensingMode('per_device')}
                                            />
                                            Per device
                                        </label>
                                    </div>
                                    {remediationLicensingMode === 'uniform' ? (
                                        <div className="grid grid-cols-2 gap-3">
                                            <select
                                                className={selectClassName}
                                                value={remediationLicenseTag}
                                                onChange={(e) => setRemediationLicenseTag(e.target.value)}
                                            >
                                                <option value="">Select tag</option>
                                                {license_tags.map((tag) => (
                                                    <option key={tag} value={tag}>
                                                        {tag}
                                                    </option>
                                                ))}
                                            </select>
                                            <select
                                                className={selectClassName}
                                                value={remediationLicenseType}
                                                onChange={(e) =>
                                                    setRemediationLicenseType(
                                                        e.target.value as LicenseTypeOption | '',
                                                    )
                                                }
                                            >
                                                <option value="">Select type</option>
                                                {license_type_options.map((type) => (
                                                    <option key={type} value={type}>
                                                        {type}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    ) : (
                                        <div className="max-h-48 space-y-2 overflow-y-auto">
                                            {deployment.devices
                                                .filter((device) =>
                                                    activeRemediation.device_ids.includes(device.id),
                                                )
                                                .map((device) => (
                                                    <div
                                                        key={device.id}
                                                        className="grid grid-cols-3 items-center gap-2"
                                                    >
                                                        <span className="truncate text-sm">{device.name}</span>
                                                        <select
                                                            className={selectClassName}
                                                            value={
                                                                remediationPerDeviceLicense[device.id]
                                                                    ?.license_tag ?? ''
                                                            }
                                                            onChange={(e) =>
                                                                setRemediationPerDeviceLicense((prev) => ({
                                                                    ...prev,
                                                                    [device.id]: {
                                                                        ...prev[device.id],
                                                                        license_tag: e.target.value,
                                                                        license_type:
                                                                            prev[device.id]?.license_type ?? '',
                                                                    },
                                                                }))
                                                            }
                                                        >
                                                            <option value="">Tag</option>
                                                            {license_tags.map((tag) => (
                                                                <option key={tag} value={tag}>
                                                                    {tag}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <select
                                                            className={selectClassName}
                                                            value={
                                                                remediationPerDeviceLicense[device.id]
                                                                    ?.license_type ?? ''
                                                            }
                                                            onChange={(e) =>
                                                                setRemediationPerDeviceLicense((prev) => ({
                                                                    ...prev,
                                                                    [device.id]: {
                                                                        license_tag:
                                                                            prev[device.id]?.license_tag ?? '',
                                                                        license_type: e.target.value as
                                                                            | LicenseTypeOption
                                                                            | '',
                                                                    },
                                                                }))
                                                            }
                                                        >
                                                            <option value="">Type</option>
                                                            {license_type_options.map((type) => (
                                                                <option key={type} value={type}>
                                                                    {type}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                ))}
                                        </div>
                                    )}
                                </div>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setActiveRemediation(null)}>
                                Cancel
                            </Button>
                            <Button
                                onClick={launchRemediation}
                                disabled={remediationLaunching}
                                data-test="launch-remediation"
                            >
                                {remediationLaunching ? 'Launching…' : 'Launch task'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {!workflow ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No workflow run yet. Select devices on the deployment page, then start a workflow here.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>
                                Online detection:{' '}
                                <span className="font-medium text-foreground">
                                    {workflow.online_detection_mode === 'webhook'
                                        ? 'Webhook'
                                        : workflow.online_detection_mode === 'stream'
                                          ? 'Streaming API'
                                          : 'Poll Central'}
                                </span>
                            </span>
                        </div>
                        <div className="grid gap-4 md:grid-cols-3">
                            <SummaryCard
                                title="In Progress"
                                count={workflow.summary.in_progress}
                                icon={<Loader2 className="size-5 text-blue-500" />}
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
                                    <CardTitle className="text-base">Licensing failures</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ul className="space-y-2 text-sm">
                                        {workflow.licensing_failures.map((row) => (
                                            <li key={row.device_id} className="rounded border p-2">
                                                <span className="font-medium">
                                                    {formatDeviceLabel(row.name, row.serial)}
                                                </span>{' '}
                                                <span className="text-muted-foreground">({row.serial})</span>
                                                <p className="text-destructive">{row.message}</p>
                                            </li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        ) : null}

                        <div className="grid gap-4 lg:grid-cols-2">
                            {workflow.devices.map((deviceCard) => (
                                <DeviceWorkflowCard key={deviceCard.id} deviceCard={deviceCard} />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

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

function DeviceWorkflowCard({ deviceCard }: { deviceCard: WorkflowDeviceCard }) {
    const [restartStep, setRestartStep] = useState(
        deviceCard.restartable_steps[0]?.step_key ?? '',
    );

    return (
        <Card data-test={`workflow-device-${deviceCard.device_id}`}>
            <CardHeader className="pb-2">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-lg">
                            {formatDeviceLabel(deviceCard.name, deviceCard.serial)}
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">{deviceCard.serial}</p>
                    </div>
                    <span
                        className={cn(
                            'rounded px-2 py-0.5 text-xs capitalize',
                            deviceCard.overall_status === 'completed' && 'bg-emerald-100 text-emerald-800',
                            deviceCard.overall_status === 'failed' && 'bg-red-100 text-red-800',
                            deviceCard.overall_status === 'in_progress' && 'bg-blue-100 text-blue-800',
                        )}
                    >
                        {deviceCard.overall_status.replace('_', ' ')}
                    </span>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div>
                    <div className="mb-1 flex justify-between text-xs text-muted-foreground">
                        <span>
                            {deviceCard.current_step_label ?? 'Waiting'}
                        </span>
                        <span>
                            {deviceCard.completed_steps}/{deviceCard.applicable_steps}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full bg-primary transition-all"
                            style={{ width: `${deviceCard.progress_percent}%` }}
                        />
                    </div>
                </div>
                {deviceCard.status_message ? (
                    <p className="text-sm text-muted-foreground">{deviceCard.status_message}</p>
                ) : null}
                <div className="max-h-40 space-y-1 overflow-y-auto text-xs">
                    {deviceCard.steps
                        .filter((step) => step.status !== 'skipped')
                        .map((step) => (
                            <div key={step.step_key} className="flex items-start gap-2">
                                <span
                                    className={cn('mt-1 size-2 shrink-0 rounded-full', statusColor(step.status))}
                                />
                                <div>
                                    <span className="font-medium">{step.label}</span>
                                    {step.message ? (
                                        <p className="text-muted-foreground">{step.message}</p>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                </div>
                {deviceCard.restartable_steps.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                        <select
                            className={selectClassName + ' max-w-[220px]'}
                            value={restartStep}
                            onChange={(e) => setRestartStep(e.target.value)}
                        >
                            {deviceCard.restartable_steps.map((step) => (
                                <option key={step.step_key} value={step.step_key}>
                                    {step.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(restartWorkflowDevice(deviceCard.id).url, {
                                    from_step: restartStep,
                                })
                            }
                        >
                            <RotateCcw className="mr-1 size-3" />
                            Restart from step
                        </Button>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
