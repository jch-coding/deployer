import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import {
    authServerSelectionKey,
    buildDeployAuthServerPayload,
    buildInitialAuthServerDeploySteps,
    deployStatusVariant,
    type AuthServer,
    type AuthServerDeployResult,
    type AuthServerDeployStepResponse,
    type AuthServerScopeType,
    type DeployProgress,
    type DeployStep,
    type DeployStepStatus,
    type ScopeOption,
    type SiteOption,
} from '@/pages/Migration/migration-types';
import type { DeviceGroupOption } from '@/pages/Migration/CreateDeploymentFromDevicesDialog';

type AuthServersCardProps = {
    authServers: AuthServer[];
    controllerName?: string;
    siteOptions: SiteOption[];
    siteCollectionOptions: ScopeOption[];
    siteCollectionOptionsError?: string | null;
    deviceGroupOptions: DeviceGroupOption[];
    deviceFunctionOptions: string[];
};

function deployStepIcon(status: DeployStepStatus) {
    switch (status) {
        case 'running':
            return <Loader2 className="size-4 shrink-0 animate-spin text-primary" />;
        case 'success':
            return <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />;
        case 'error':
            return <AlertCircle className="size-4 shrink-0 text-destructive" />;
        case 'skipped':
            return <span className="bg-muted-foreground size-2 shrink-0 rounded-full" />;
        default:
            return <span className="bg-muted size-2 shrink-0 rounded-full" />;
    }
}

function scopeOptionsForType(
    scopeType: AuthServerScopeType,
    siteOptions: SiteOption[],
    siteCollectionOptions: ScopeOption[],
    deviceGroupOptions: DeviceGroupOption[],
): ScopeOption[] {
    switch (scopeType) {
        case 'site-collection':
            return siteCollectionOptions;
        case 'device-group':
            return deviceGroupOptions.map((group) => ({
                scopeId: group.scopeId,
                scopeName: group.scopeName,
            }));
        case 'site':
        default:
            return siteOptions.map((site) => ({
                scopeId: site.siteId,
                scopeName: site.siteName,
            }));
    }
}

export default function AuthServersCard({
    authServers,
    controllerName = '',
    siteOptions,
    siteCollectionOptions,
    siteCollectionOptionsError = null,
    deviceGroupOptions,
    deviceFunctionOptions,
}: AuthServersCardProps) {
    const selectionPrefix = controllerName || 'all';
    const idSuffix = controllerName.replace(/[^a-zA-Z0-9_-]/g, '-') || 'all';

    const [scopeType, setScopeType] = useState<AuthServerScopeType>('site');
    const [scopeId, setScopeId] = useState('');
    const [deviceFunction, setDeviceFunction] = useState('CAMPUS_AP');
    const [expandedServers, setExpandedServers] = useState<Record<string, boolean>>({});
    const [selectedServerKeys, setSelectedServerKeys] = useState<Set<string>>(() => new Set());
    const [deploying, setDeploying] = useState(false);
    const [deployStarted, setDeployStarted] = useState(false);
    const [deploySteps, setDeploySteps] = useState<DeployStep[]>([]);
    const [deployProgress, setDeployProgress] = useState<DeployProgress>({
        current: 0,
        total: 0,
        percent: 0,
        message: '',
    });
    const [deployError, setDeployError] = useState<string | null>(null);
    const [liveDeployResults, setLiveDeployResults] = useState<AuthServerDeployResult[]>([]);

    useEffect(() => {
        setSelectedServerKeys(
            new Set(
                authServers.map((server) => authServerSelectionKey(selectionPrefix, server.name)),
            ),
        );
    }, [authServers, selectionPrefix]);

    useEffect(() => {
        setScopeId('');
    }, [scopeType]);

    const scopeOptions = useMemo(
        () =>
            scopeOptionsForType(
                scopeType,
                siteOptions,
                siteCollectionOptions,
                deviceGroupOptions,
            ),
        [scopeType, siteOptions, siteCollectionOptions, deviceGroupOptions],
    );

    const selectedAuthServers = useMemo(
        () =>
            authServers.filter((server) =>
                selectedServerKeys.has(authServerSelectionKey(selectionPrefix, server.name)),
            ),
        [authServers, selectedServerKeys, selectionPrefix],
    );

    const allServersSelected =
        authServers.length > 0 &&
        authServers.every((server) =>
            selectedServerKeys.has(authServerSelectionKey(selectionPrefix, server.name)),
        );
    const someServersSelected =
        !allServersSelected &&
        authServers.some((server) =>
            selectedServerKeys.has(authServerSelectionKey(selectionPrefix, server.name)),
        );

    const handleDeploy = async () => {
        if (scopeId.trim() === '') {
            toast.error('Please select a scope before deploying auth servers');

            return;
        }

        if (deviceFunction.trim() === '') {
            toast.error('Please select a device function before deploying auth servers');

            return;
        }

        if (authServers.length === 0) {
            toast.error('No auth servers to deploy');

            return;
        }

        if (selectedAuthServers.length === 0) {
            toast.error('Please select at least one auth server to deploy');

            return;
        }

        const servers = selectedAuthServers.map(buildDeployAuthServerPayload);
        const initialSteps = buildInitialAuthServerDeploySteps(selectedAuthServers);

        setDeployStarted(true);
        setDeploySteps(initialSteps);
        setLiveDeployResults([]);
        setDeployError(null);
        setDeploying(true);
        setDeployProgress({
            current: 0,
            total: initialSteps.length,
            percent: 0,
            message: 'Starting deployment...',
        });

        let total = initialSteps.length;
        let step = 0;

        try {
            while (step < total) {
                setDeploySteps((current) =>
                    current.map((deployStep, index) =>
                        index === step ? { ...deployStep, status: 'running' } : deployStep,
                    ),
                );

                const response = await fetch(`/migrations/deploy-auth-servers/step/${step}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        scope_id: scopeId,
                        device_function: deviceFunction,
                        servers,
                    }),
                });

                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    throw new Error(
                        body?.message ?? `Deploy failed (HTTP ${response.status}).`,
                    );
                }

                const data = (await response.json()) as AuthServerDeployStepResponse;

                setDeployProgress(data.progress);
                total = data.progress.total;

                setDeploySteps((current) =>
                    current.map((deployStep, index) =>
                        index === step
                            ? {
                                  ...deployStep,
                                  status: data.step.status,
                                  message: data.step.message,
                              }
                            : deployStep,
                    ),
                );

                setLiveDeployResults((current) => [
                    ...current,
                    ...data.partial.deploy_results,
                ]);

                step += 1;
            }

            setDeployProgress((current) => ({
                ...current,
                percent: 100,
                message: 'Deployment complete.',
            }));
            toast.success(
                controllerName
                    ? `Auth server deployment finished for ${controllerName}`
                    : 'Auth server deployment finished',
            );
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Auth server deployment failed';
            setDeployError(message);
            setDeploySteps((current) =>
                current.map((deployStep, index) =>
                    index === step && deployStep.status === 'running'
                        ? { ...deployStep, status: 'error', message }
                        : deployStep,
                ),
            );
            toast.error(
                controllerName
                    ? `Auth server deployment failed for ${controllerName}`
                    : 'Auth server deployment failed',
            );
        } finally {
            setDeploying(false);
        }
    };

    const toggleServerExpanded = (serverKey: string) => {
        setExpandedServers((current) => ({
            ...current,
            [serverKey]: !current[serverKey],
        }));
    };

    const toggleServerSelected = (serverKey: string, checked: boolean) => {
        setSelectedServerKeys((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(serverKey);
            } else {
                next.delete(serverKey);
            }

            return next;
        });
    };

    const toggleAllServers = (checked: boolean) => {
        setSelectedServerKeys(
            checked
                ? new Set(
                      authServers.map((server) =>
                          authServerSelectionKey(selectionPrefix, server.name),
                      ),
                  )
                : new Set(),
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Auth servers</CardTitle>
                <CardDescription>
                    RADIUS authentication servers extracted from `show running-config`. Matching
                    rfc-3576 servers enable CoA (AUTH_AND_COA).
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="grid max-w-xl gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor={`auth-scope-type-${idSuffix}`}>Scope type</Label>
                        <Select
                            value={scopeType}
                            onValueChange={(value) =>
                                setScopeType(value as AuthServerScopeType)
                            }
                        >
                            <SelectTrigger id={`auth-scope-type-${idSuffix}`}>
                                <SelectValue placeholder="Select scope type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="site-collection">Site collection</SelectItem>
                                <SelectItem value="site">Site</SelectItem>
                                <SelectItem value="device-group">Device group</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`auth-scope-id-${idSuffix}`}>Scope</Label>
                        <Select value={scopeId} onValueChange={setScopeId}>
                            <SelectTrigger id={`auth-scope-id-${idSuffix}`}>
                                <SelectValue placeholder="Select a scope" />
                            </SelectTrigger>
                            <SelectContent>
                                {scopeOptions.map((option) => (
                                    <SelectItem key={option.scopeId} value={option.scopeId}>
                                        {option.scopeName} ({option.scopeId})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {scopeType === 'site-collection' && siteCollectionOptionsError && (
                            <p className="text-destructive text-sm">{siteCollectionOptionsError}</p>
                        )}
                        {scopeId.trim() === '' && (
                            <p className="text-muted-foreground text-sm">
                                Select a scope to deploy auth servers.
                            </p>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`auth-device-function-${idSuffix}`}>Device function</Label>
                        <Select value={deviceFunction} onValueChange={setDeviceFunction}>
                            <SelectTrigger id={`auth-device-function-${idSuffix}`}>
                                <SelectValue placeholder="Select device function" />
                            </SelectTrigger>
                            <SelectContent>
                                {deviceFunctionOptions.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-2 font-medium">
                                    <Checkbox
                                        checked={
                                            allServersSelected
                                                ? true
                                                : someServersSelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        aria-label="Select all auth servers for deployment"
                                        onCheckedChange={(checked) =>
                                            toggleAllServers(checked === true)
                                        }
                                        disabled={authServers.length === 0}
                                    />
                                </th>
                                <th className="px-2 py-2 font-medium">Name</th>
                                <th className="px-2 py-2 font-medium">Host</th>
                                <th className="px-2 py-2 font-medium">CoA</th>
                                <th className="px-2 py-2 font-medium">Warnings</th>
                                <th className="px-2 py-2 font-medium">Body</th>
                            </tr>
                        </thead>
                        <tbody>
                            {authServers.map((server) => {
                                const serverKey = authServerSelectionKey(
                                    selectionPrefix,
                                    server.name,
                                );

                                return (
                                    <tr key={serverKey} className="border-b align-top">
                                        <td className="px-2 py-2">
                                            <Checkbox
                                                id={`deploy-auth-server-${serverKey}`}
                                                checked={selectedServerKeys.has(serverKey)}
                                                aria-label={`Deploy ${server.name}`}
                                                onCheckedChange={(checked) =>
                                                    toggleServerSelected(
                                                        serverKey,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="px-2 py-2 font-mono text-xs">
                                            <label
                                                htmlFor={`deploy-auth-server-${serverKey}`}
                                                className="cursor-pointer"
                                            >
                                                {server.name}
                                            </label>
                                        </td>
                                        <td className="px-2 py-2 font-mono text-xs">
                                            {server.host || '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {server.has_coa ? (
                                                <Badge variant="secondary">AUTH_AND_COA</Badge>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {server.warnings.length > 0 ? (
                                                <ul className="text-destructive list-inside list-disc text-xs">
                                                    {server.warnings.map((warning) => (
                                                        <li key={warning}>{warning}</li>
                                                    ))}
                                                </ul>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            <button
                                                type="button"
                                                className="text-muted-foreground inline-flex items-center gap-1 text-xs"
                                                onClick={() => toggleServerExpanded(serverKey)}
                                            >
                                                {expandedServers[serverKey] ? (
                                                    <ChevronDown className="size-3" />
                                                ) : (
                                                    <ChevronRight className="size-3" />
                                                )}
                                                JSON
                                            </button>
                                            {expandedServers[serverKey] && (
                                                <pre className="bg-muted mt-2 max-h-48 overflow-auto rounded p-2 text-xs">
                                                    {JSON.stringify(server.body, null, 2)}
                                                </pre>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {authServers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="text-muted-foreground px-2 py-4 text-center"
                                    >
                                        No RADIUS auth servers found in running-config.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        onClick={handleDeploy}
                        disabled={deploying || selectedAuthServers.length === 0}
                    >
                        {deploying && <Loader2 className="size-4 animate-spin" />}
                        Deploy {selectedAuthServers.length} auth server
                        {selectedAuthServers.length === 1 ? '' : 's'}
                    </Button>
                </div>

                {deployStarted && (
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-col gap-1">
                            <div className="flex items-center justify-between text-sm">
                                <span>{deployProgress.message || 'Deploying…'}</span>
                                <span className="text-muted-foreground">
                                    {deployProgress.current}/{deployProgress.total} (
                                    {deployProgress.percent}%)
                                </span>
                            </div>
                            <progress
                                className="h-2 w-full"
                                value={deployProgress.percent}
                                max={100}
                            />
                        </div>

                        <ul className="flex flex-col gap-1">
                            {deploySteps.map((step) => (
                                <li
                                    key={step.key}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    {deployStepIcon(step.status)}
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">{step.label}</div>
                                        {step.message && (
                                            <div
                                                className={cn(
                                                    'text-xs',
                                                    step.status === 'error'
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {step.message}
                                            </div>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {deployError && (
                            <p className="text-destructive text-sm">{deployError}</p>
                        )}

                        {liveDeployResults.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">Auth server deployment results</h3>
                                <ul className="flex flex-col gap-1">
                                    {liveDeployResults.map((result) => (
                                        <li
                                            key={`${result.name}-${result.status}-${result.message}`}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Badge variant={deployStatusVariant(result.status)}>
                                                {result.status}
                                            </Badge>
                                            <span className="font-mono text-xs">{result.name}</span>
                                            <span className="text-muted-foreground">
                                                {result.message}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
