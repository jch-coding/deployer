import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, Download, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    downloadMigrationDevicesCsv,
    downloadMigrationLldpCsv,
} from '@/lib/migration-csv';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import CreateDeploymentFromDevicesDialog, {
    type DeviceGroupOption,
} from '@/pages/Migration/CreateDeploymentFromDevicesDialog';
import {
    buildDeployProfilePayload,
    buildInitialDeploySteps,
    deployStatusVariant,
    isFreezerSite,
    profileSelectionKey,
    type DeployProgress,
    type DeployResult,
    type DeployStep,
    type DeployStepResponse,
    type DeployStepStatus,
    type NamedVlanDeployResult,
    type ParsedController,
    type SiteOption,
} from '@/pages/Migration/migration-types';

type ControllerMigrationSectionProps = {
    controller: ParsedController;
    siteOptions: SiteOption[];
    groupOptions: DeviceGroupOption[];
    parsedControllers: ParsedController[];
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

export default function ControllerMigrationSection({
    controller,
    siteOptions,
    groupOptions,
    parsedControllers,
}: ControllerMigrationSectionProps) {
    const { controller_name, devices, lldp_neighbors, wlan_profiles } = controller;

    const [scopeId, setScopeId] = useState('');
    const [expandedProfiles, setExpandedProfiles] = useState<Record<string, boolean>>({});
    const [selectedProfileKeys, setSelectedProfileKeys] = useState<Set<string>>(() => new Set());
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
    const [liveDeployResults, setLiveDeployResults] = useState<DeployResult[]>([]);
    const [liveNamedVlanDeployResults, setLiveNamedVlanDeployResults] = useState<
        NamedVlanDeployResult[]
    >([]);
    const [vlanOverrides, setVlanOverrides] = useState<Record<string, string>>({});

    useEffect(() => {
        setSelectedProfileKeys(
            new Set(
                wlan_profiles.map((profile) =>
                    profileSelectionKey(controller_name, profile.ssid_profile_name),
                ),
            ),
        );
    }, [controller_name, wlan_profiles]);

    const selectedWlanProfiles = useMemo(
        () =>
            wlan_profiles.filter((profile) =>
                selectedProfileKeys.has(
                    profileSelectionKey(controller_name, profile.ssid_profile_name),
                ),
            ),
        [controller_name, wlan_profiles, selectedProfileKeys],
    );

    const allProfilesSelected =
        wlan_profiles.length > 0 &&
        wlan_profiles.every((profile) =>
            selectedProfileKeys.has(
                profileSelectionKey(controller_name, profile.ssid_profile_name),
            ),
        );
    const someProfilesSelected =
        !allProfilesSelected &&
        wlan_profiles.some((profile) =>
            selectedProfileKeys.has(
                profileSelectionKey(controller_name, profile.ssid_profile_name),
            ),
        );

    const selectedSiteName = useMemo(
        () => siteOptions.find((site) => site.siteId === scopeId)?.siteName ?? '',
        [siteOptions, scopeId],
    );

    const showFreezerHint = isFreezerSite(selectedSiteName);
    const scopeSelectId = `scope_id-${controller_name.replace(/[^a-zA-Z0-9_-]/g, '-')}`;

    const handleDeploy = async () => {
        if (scopeId.trim() === '') {
            toast.error(`Please select a site before deploying WLAN profiles for ${controller_name}`);

            return;
        }

        if (wlan_profiles.length === 0) {
            toast.error(`No WLAN profiles to deploy for ${controller_name}`);

            return;
        }

        if (selectedWlanProfiles.length === 0) {
            toast.error(`Please select at least one WLAN profile to deploy for ${controller_name}`);

            return;
        }

        const profiles = selectedWlanProfiles.map((profile) =>
            buildDeployProfilePayload(
                profile,
                vlanOverrides[
                    profileSelectionKey(controller_name, profile.ssid_profile_name)
                ],
            ),
        );

        const initialSteps = buildInitialDeploySteps(selectedWlanProfiles, showFreezerHint);

        setDeployStarted(true);
        setDeploySteps(initialSteps);
        setLiveDeployResults([]);
        setLiveNamedVlanDeployResults([]);
        setDeployError(null);
        setDeploying(true);
        setDeployProgress({
            current: 0,
            total: initialSteps.length,
            percent: 0,
            message: 'Starting deployment...',
        });

        let context: DeployStepResponse['context'] = { named_vlan_profiles: [] };
        let total = initialSteps.length;
        let step = 0;

        try {
            while (step < total) {
                setDeploySteps((current) =>
                    current.map((deployStep, index) =>
                        index === step ? { ...deployStep, status: 'running' } : deployStep,
                    ),
                );

                const response = await fetch(`/migrations/deploy-wlan/step/${step}`, {
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
                        profiles,
                        context,
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

                const data = (await response.json()) as DeployStepResponse;

                setDeployProgress(data.progress);
                total = data.progress.total;
                context = data.context ?? context;

                setDeploySteps((current) => {
                    let next = current.map((deployStep, index) =>
                        index === step
                            ? {
                                  ...deployStep,
                                  status: data.step.status,
                                  message: data.step.message,
                              }
                            : deployStep,
                    );

                    if (
                        data.step.key === 'named-vlan-fetch' &&
                        data.context.named_vlan_profiles.length > 0
                    ) {
                        const existingKeys = new Set(next.map((deployStep) => deployStep.key));

                        for (const profile of data.context.named_vlan_profiles) {
                            const name = String(profile.name ?? '').trim();

                            if (name === '' || existingKeys.has(`named-vlan-${name}`)) {
                                continue;
                            }

                            next.push({
                                key: `named-vlan-${name}`,
                                label: `Deploy named VLAN: ${name} (+200 offset)`,
                                status: 'pending',
                            });
                            existingKeys.add(`named-vlan-${name}`);
                        }
                    }

                    return next;
                });

                setLiveDeployResults((current) => [
                    ...current,
                    ...data.partial.deploy_results,
                ]);
                setLiveNamedVlanDeployResults((current) => [
                    ...current,
                    ...data.partial.named_vlan_deploy_results,
                ]);

                step += 1;
            }

            setDeployProgress((current) => ({
                ...current,
                percent: 100,
                message: 'Deployment complete.',
            }));
            toast.success(`WLAN profile deployment finished for ${controller_name}`);
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'WLAN profile deployment failed';
            setDeployError(message);
            setDeploySteps((current) =>
                current.map((deployStep, index) =>
                    index === step && deployStep.status === 'running'
                        ? { ...deployStep, status: 'error', message }
                        : deployStep,
                ),
            );
            toast.error(`WLAN profile deployment failed for ${controller_name}`);
        } finally {
            setDeploying(false);
        }
    };

    const toggleProfileExpanded = (profileKey: string) => {
        setExpandedProfiles((current) => ({
            ...current,
            [profileKey]: !current[profileKey],
        }));
    };

    const toggleProfileSelected = (profileKey: string, checked: boolean) => {
        setSelectedProfileKeys((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(profileKey);
            } else {
                next.delete(profileKey);
            }

            return next;
        });
    };

    const toggleAllProfiles = (checked: boolean) => {
        setSelectedProfileKeys(
            checked
                ? new Set(
                      wlan_profiles.map((profile) =>
                          profileSelectionKey(controller_name, profile.ssid_profile_name),
                      ),
                  )
                : new Set(),
        );
    };

    return (
        <div className="flex flex-col gap-6">
            <Card>
                <CardHeader>
                    <CardTitle>{controller_name}</CardTitle>
                    <CardDescription>
                        Controller section with AP inventory, LLDP neighbors, and WLAN profiles.
                    </CardDescription>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-4">
                    <div>
                        <CardTitle>AP devices</CardTitle>
                        <CardDescription>
                            {devices.length} access points extracted from `show ap database long`.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <CreateDeploymentFromDevicesDialog
                            devices={devices}
                            siteOptions={siteOptions}
                            groupOptions={groupOptions}
                            parsedControllers={parsedControllers}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                downloadMigrationDevicesCsv(devices, controller_name)
                            }
                            disabled={devices.length === 0}
                        >
                            <Download className="size-4" />
                            Download CSV
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-2 font-medium">Name</th>
                                <th className="px-2 py-2 font-medium">Serial</th>
                                <th className="px-2 py-2 font-medium">MAC</th>
                            </tr>
                        </thead>
                        <tbody>
                            {devices.map((device) => (
                                <tr key={device.serial} className="border-b">
                                    <td className="px-2 py-2">{device.name}</td>
                                    <td className="px-2 py-2 font-mono text-xs">
                                        {device.serial}
                                    </td>
                                    <td className="px-2 py-2 font-mono text-xs">{device.mac}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-4">
                    <div>
                        <CardTitle>LLDP neighbors</CardTitle>
                        <CardDescription>
                            Switches and ports from `show ap lldp neighbors`.
                        </CardDescription>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            downloadMigrationLldpCsv(lldp_neighbors, controller_name)
                        }
                        disabled={lldp_neighbors.length === 0}
                    >
                        <Download className="size-4" />
                        Download CSV
                    </Button>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-2 font-medium">Switch</th>
                                <th className="px-2 py-2 font-medium">Ports</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lldp_neighbors.map((neighbor) => (
                                <tr key={neighbor.switch} className="border-b">
                                    <td className="px-2 py-2">{neighbor.switch}</td>
                                    <td className="px-2 py-2 font-mono text-xs">
                                        {neighbor.ports.join(', ')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>WLAN SSID profiles</CardTitle>
                    <CardDescription>
                        Profiles extracted from `show running-config` with mapped VLAN names for
                        Central deployment.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <div className="grid max-w-md gap-2">
                        <Label htmlFor={scopeSelectId}>Target site</Label>
                        <Select value={scopeId} onValueChange={setScopeId}>
                            <SelectTrigger id={scopeSelectId}>
                                <SelectValue placeholder="Select a site" />
                            </SelectTrigger>
                            <SelectContent>
                                {siteOptions.map((site) => (
                                    <SelectItem key={site.siteId} value={site.siteId}>
                                        {site.siteName} ({site.siteId})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {scopeId.trim() === '' && (
                            <p className="text-muted-foreground text-sm">
                                Select a site to deploy WLAN profiles.
                            </p>
                        )}
                        {showFreezerHint && (
                            <p className="text-muted-foreground text-sm">
                                Named VLAN profiles will be offset by +200 after WLAN deploy for
                                this Freezer site.
                            </p>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="px-2 py-2 font-medium">
                                        <Checkbox
                                            checked={
                                                allProfilesSelected
                                                    ? true
                                                    : someProfilesSelected
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            aria-label={`Select all WLAN profiles for ${controller_name}`}
                                            onCheckedChange={(checked) =>
                                                toggleAllProfiles(checked === true)
                                            }
                                            disabled={wlan_profiles.length === 0}
                                        />
                                    </th>
                                    <th className="px-2 py-2 font-medium">Profile</th>
                                    <th className="px-2 py-2 font-medium">ESSID</th>
                                    <th className="px-2 py-2 font-medium">VLAN</th>
                                    <th className="px-2 py-2 font-medium">Passphrase</th>
                                    <th className="px-2 py-2 font-medium">Warnings</th>
                                    <th className="px-2 py-2 font-medium">Body</th>
                                </tr>
                            </thead>
                            <tbody>
                                {wlan_profiles.map((profile) => {
                                    const profileKey = profileSelectionKey(
                                        controller_name,
                                        profile.ssid_profile_name,
                                    );
                                    const essid =
                                        (profile.body.essid as { name?: string } | undefined)
                                            ?.name ?? '';
                                    const passphrase =
                                        (
                                            profile.body['personal-security'] as
                                                | { 'wpa-passphrase'?: string | null }
                                                | undefined
                                        )?.['wpa-passphrase'] ?? null;

                                    return (
                                        <tr key={profileKey} className="border-b align-top">
                                            <td className="px-2 py-2">
                                                <Checkbox
                                                    id={`deploy-profile-${profileKey}`}
                                                    checked={selectedProfileKeys.has(profileKey)}
                                                    aria-label={`Deploy ${profile.ssid_profile_name}`}
                                                    onCheckedChange={(checked) =>
                                                        toggleProfileSelected(
                                                            profileKey,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                            </td>
                                            <td className="px-2 py-2 font-mono text-xs">
                                                <label
                                                    htmlFor={`deploy-profile-${profileKey}`}
                                                    className="cursor-pointer"
                                                >
                                                    {profile.ssid_profile_name}
                                                </label>
                                            </td>
                                            <td className="px-2 py-2">{essid || '—'}</td>
                                            <td className="px-2 py-2">
                                                {profile.raw_vlan ? (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {profile.raw_vlan} →{' '}
                                                        {profile.vlan_name ?? '—'}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground block text-xs">
                                                        —
                                                    </span>
                                                )}
                                                <Input
                                                    id={`vlan-override-${profileKey}`}
                                                    className="mt-1 h-8 font-mono text-xs"
                                                    placeholder={
                                                        profile.vlan_name ??
                                                        String(profile.body['vlan-name'] ?? '')
                                                    }
                                                    value={
                                                        vlanOverrides[profileKey] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setVlanOverrides((current) => ({
                                                            ...current,
                                                            [profileKey]: event.target.value,
                                                        }))
                                                    }
                                                    aria-label={`Named VLAN override for ${profile.ssid_profile_name}`}
                                                />
                                            </td>
                                            <td className="px-2 py-2">
                                                {passphrase ? 'Yes' : 'No'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {profile.warnings.length > 0 ? (
                                                    <span className="text-amber-600 text-xs">
                                                        {profile.warnings.join(', ')}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        toggleProfileExpanded(profileKey)
                                                    }
                                                >
                                                    {expandedProfiles[profileKey] ? (
                                                        <ChevronDown className="size-4" />
                                                    ) : (
                                                        <ChevronRight className="size-4" />
                                                    )}
                                                </Button>
                                                {expandedProfiles[profileKey] && (
                                                    <pre className="mt-2 max-w-xl overflow-x-auto rounded-md bg-muted p-2 text-xs">
                                                        {JSON.stringify(profile.body, null, 2)}
                                                    </pre>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <Button
                        type="button"
                        onClick={() => void handleDeploy()}
                        disabled={
                            deploying || scopeId.trim() === '' || selectedWlanProfiles.length === 0
                        }
                    >
                        {deploying ? <Loader2 className="size-4 animate-spin" /> : null}
                        Deploy {selectedWlanProfiles.length} WLAN profile
                        {selectedWlanProfiles.length === 1 ? '' : 's'}
                    </Button>

                    {deployStarted && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">Deploy progress</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <div
                                        className="bg-muted h-2 w-full overflow-hidden rounded-full"
                                        role="progressbar"
                                        aria-valuenow={deployProgress.percent}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                    >
                                        <div
                                            className="bg-primary h-full rounded-full transition-all duration-300"
                                            style={{
                                                width: `${deployProgress.percent}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        Step {deployProgress.current} of {deployProgress.total}
                                        {deployProgress.percent > 0 &&
                                            ` (${deployProgress.percent}%)`}
                                    </p>
                                    <p className="text-sm font-medium">{deployProgress.message}</p>
                                    {deployError && (
                                        <p className="text-destructive text-sm">{deployError}</p>
                                    )}
                                </div>

                                <div className="max-h-64 space-y-2 overflow-y-auto">
                                    {deploySteps.map((deployStep) => (
                                        <div
                                            key={deployStep.key}
                                            className={cn(
                                                'flex items-start gap-2 text-sm',
                                                deployStep.status === 'pending' &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {deployStepIcon(deployStep.status)}
                                            <div>
                                                <span className="font-medium">
                                                    {deployStep.label}
                                                </span>
                                                {deployStep.message ? (
                                                    <p className="text-muted-foreground text-xs">
                                                        {deployStep.message}
                                                    </p>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {liveDeployResults.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-medium">WLAN deployment results</h3>
                            {liveDeployResults.map((result) => (
                                <div
                                    key={result.ssid}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <Badge variant={deployStatusVariant(result.status)}>
                                        {result.status}
                                    </Badge>
                                    <span className="font-mono text-xs">{result.ssid}</span>
                                    <span className="text-muted-foreground">{result.message}</span>
                                </div>
                            ))}
                        </div>
                    )}

                    {liveNamedVlanDeployResults.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-medium">Named VLAN deployment results</h3>
                            {liveNamedVlanDeployResults.map((result) => (
                                <div
                                    key={result.name}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <Badge variant={deployStatusVariant(result.status)}>
                                        {result.status}
                                    </Badge>
                                    <span className="font-mono text-xs">{result.name}</span>
                                    <span className="text-muted-foreground">{result.message}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
