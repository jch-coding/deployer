import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, Download, FileUp, Loader2, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
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
import AppLayout from '@/layouts/app-layout';
import {
    downloadMigrationDevicesCsv,
    downloadMigrationLldpCsv,
    type MigrationDevice,
    type MigrationLldpNeighbor,
} from '@/lib/migration-csv';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import { index as clientsIndex } from '@/routes/clients';
import { index as migrationsIndex, parse as migrationsParse } from '@/routes/migrations';
import type { BreadcrumbItem, SharedData } from '@/types';

type SiteOption = {
    siteId: string;
    siteName: string;
};

type WlanProfile = {
    ssid_profile_name: string;
    raw_vlan: string | null;
    vlan_name: string | null;
    body: Record<string, unknown>;
    warnings: string[];
};

type ParsedController = {
    controller_name: string;
    devices: MigrationDevice[];
    lldp_neighbors: MigrationLldpNeighbor[];
    wlan_profiles: WlanProfile[];
};

type DeployResult = {
    ssid: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

type NamedVlanDeployResult = {
    name: string;
    status: 'success' | 'error' | 'skipped';
    message: string;
};

type DeployStepStatus = 'pending' | 'running' | 'success' | 'error' | 'skipped';

type DeployStep = {
    key: string;
    label: string;
    status: DeployStepStatus;
    message?: string;
};

type DeployProgress = {
    current: number;
    total: number;
    percent: number;
    message: string;
};

type DeployStepResponse = {
    progress: DeployProgress;
    step: {
        key: string;
        label: string;
        status: 'success' | 'error' | 'skipped';
        message: string;
    };
    partial: {
        deploy_results: DeployResult[];
        named_vlan_deploy_results: NamedVlanDeployResult[];
    };
    context: {
        named_vlan_profiles: Array<Record<string, unknown>>;
    };
};

type MigrationIndexProps = {
    site_options: SiteOption[];
    parsed_controllers: ParsedController[];
    deploy_results: DeployResult[];
    named_vlan_deploy_results: NamedVlanDeployResult[];
    selected_scope_id?: string;
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
} & SharedData;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Migrations', href: migrationsIndex().url },
];

function deployStatusVariant(
    status: DeployResult['status'] | NamedVlanDeployResult['status'],
): 'default' | 'destructive' | 'secondary' {
    switch (status) {
        case 'success':
            return 'default';
        case 'error':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function isFreezerSite(siteName: string): boolean {
    return siteName.includes('Freezer') && !siteName.includes('Hub-Freezer');
}

function buildInitialDeploySteps(profiles: WlanProfile[], isFreezer: boolean): DeployStep[] {
    const steps: DeployStep[] = profiles.map((profile) => ({
        key: `wlan-${profile.ssid_profile_name}`,
        label: `Deploy WLAN profile: ${profile.ssid_profile_name}`,
        status: 'pending',
    }));

    if (isFreezer) {
        steps.push({
            key: 'named-vlan-fetch',
            label: 'Fetch named VLAN profiles from Central',
            status: 'pending',
        });
    }

    return steps;
}

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

export default function Index() {
    const {
        current_client,
        site_options,
        parsed_controllers,
        deploy_results,
        named_vlan_deploy_results,
        selected_scope_id,
        central_sites_cache,
        central_groups_cache,
    } = usePage<MigrationIndexProps>().props;

    const [scopeId, setScopeId] = useState(selected_scope_id ?? '');
    const [expandedProfiles, setExpandedProfiles] = useState<Record<string, boolean>>({});
    const [selectedProfileNames, setSelectedProfileNames] = useState<Set<string>>(
        () => new Set(),
    );
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
    const [liveDeployResults, setLiveDeployResults] = useState<DeployResult[]>(deploy_results);
    const [liveNamedVlanDeployResults, setLiveNamedVlanDeployResults] = useState<
        NamedVlanDeployResult[]
    >(named_vlan_deploy_results);

    useEffect(() => {
        setLiveDeployResults(deploy_results);
    }, [deploy_results]);

    useEffect(() => {
        setLiveNamedVlanDeployResults(named_vlan_deploy_results);
    }, [named_vlan_deploy_results]);

    const parseForm = useForm<{ config_file: File | null }>({
        config_file: null,
    });

    const allDevices = useMemo(
        () =>
            parsed_controllers.flatMap((controller) =>
                controller.devices.map((device) => ({
                    ...device,
                    controller: controller.controller_name,
                })),
            ),
        [parsed_controllers],
    );

    const allLldpNeighbors = useMemo(() => {
        const bySwitch = new Map<string, Set<string>>();

        for (const controller of parsed_controllers) {
            for (const neighbor of controller.lldp_neighbors) {
                if (!bySwitch.has(neighbor.switch)) {
                    bySwitch.set(neighbor.switch, new Set());
                }

                for (const port of neighbor.ports) {
                    bySwitch.get(neighbor.switch)?.add(port);
                }
            }
        }

        return Array.from(bySwitch.entries())
            .map(([switchName, ports]) => ({
                switch: switchName,
                ports: Array.from(ports).sort(),
            }))
            .sort((a, b) => a.switch.localeCompare(b.switch));
    }, [parsed_controllers]);

    const allWlanProfiles = useMemo(
        () => parsed_controllers.flatMap((controller) => controller.wlan_profiles),
        [parsed_controllers],
    );

    useEffect(() => {
        setSelectedProfileNames(
            new Set(allWlanProfiles.map((profile) => profile.ssid_profile_name)),
        );
    }, [allWlanProfiles]);

    const selectedWlanProfiles = useMemo(
        () =>
            allWlanProfiles.filter((profile) =>
                selectedProfileNames.has(profile.ssid_profile_name),
            ),
        [allWlanProfiles, selectedProfileNames],
    );

    const allProfilesSelected =
        allWlanProfiles.length > 0 &&
        allWlanProfiles.every((profile) =>
            selectedProfileNames.has(profile.ssid_profile_name),
        );
    const someProfilesSelected =
        !allProfilesSelected &&
        allWlanProfiles.some((profile) =>
            selectedProfileNames.has(profile.ssid_profile_name),
        );

    const selectedSiteName = useMemo(
        () => site_options.find((site) => site.siteId === scopeId)?.siteName ?? '',
        [site_options, scopeId],
    );

    const showFreezerHint = isFreezerSite(selectedSiteName);

    const handleParseSubmit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!parseForm.data.config_file) {
            toast.error('Please select a config file to upload');

            return;
        }

        parseForm.post(migrationsParse().url, {
            forceFormData: true,
            onSuccess: () => toast.success('Config file parsed successfully'),
            onError: () => toast.error('Failed to parse config file'),
        });
    };

    const handleDeploy = async () => {
        if (scopeId.trim() === '') {
            toast.error('Please select a site before deploying WLAN profiles');

            return;
        }

        if (allWlanProfiles.length === 0) {
            toast.error('No WLAN profiles to deploy');

            return;
        }

        if (selectedWlanProfiles.length === 0) {
            toast.error('Please select at least one WLAN profile to deploy');

            return;
        }

        const profiles = selectedWlanProfiles.map((profile) => ({
            ssid_profile_name: profile.ssid_profile_name,
            body: profile.body,
        }));

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
            toast.success('WLAN profile deployment finished');
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
            toast.error('WLAN profile deployment failed');
        } finally {
            setDeploying(false);
        }
    };

    const toggleProfileExpanded = (profileName: string) => {
        setExpandedProfiles((current) => ({
            ...current,
            [profileName]: !current[profileName],
        }));
    };

    const toggleProfileSelected = (profileName: string, checked: boolean) => {
        setSelectedProfileNames((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(profileName);
            } else {
                next.delete(profileName);
            }

            return next;
        });
    };

    const toggleAllProfiles = (checked: boolean) => {
        setSelectedProfileNames(
            checked
                ? new Set(allWlanProfiles.map((profile) => profile.ssid_profile_name))
                : new Set(),
        );
    };

    if (!current_client) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Migrations" />
                <div className="p-4">
                    <p className="text-muted-foreground text-sm">
                        Please{' '}
                        <a href={clientsIndex().url} className="text-primary underline">
                            select a client
                        </a>{' '}
                        to use migrations.
                    </p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Migrations" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Migrations</h1>
                        <p className="text-muted-foreground text-sm">
                            Upload Aruba controller config dumps to extract AP inventory, LLDP
                            neighbors, and WLAN SSID profiles.
                        </p>
                    </div>
                    <CentralScopeRefreshButtons
                        centralSitesCache={central_sites_cache}
                        centralGroupsCache={central_groups_cache}
                        onRefreshed={() => router.reload({ only: ['site_options', 'central_sites_cache', 'central_groups_cache'] })}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileUp className="size-5" />
                            Upload config file
                        </CardTitle>
                        <CardDescription>
                            Accepts `.txt` or `.log` files with controller output including `show ap
                            database long`, `show ap lldp neighbors`, and `show running-config`.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleParseSubmit} className="flex flex-col gap-4">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="config_file">Config file</Label>
                                <input
                                    id="config_file"
                                    type="file"
                                    name="config_file"
                                    accept=".txt,.log"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0] ?? null;
                                        parseForm.setData('config_file', file);
                                    }}
                                    className="text-sm"
                                />
                                {parseForm.errors.config_file && (
                                    <p className="text-destructive text-sm">
                                        {parseForm.errors.config_file}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={parseForm.processing || !parseForm.data.config_file}
                                >
                                    {parseForm.processing ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <Upload className="size-4" />
                                    )}
                                    Parse file
                                </Button>
                                {parseForm.progress && (
                                    <progress value={parseForm.progress.percentage} max="100">
                                        {parseForm.progress.percentage}%
                                    </progress>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {parsed_controllers.length > 0 && (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>Controllers</CardTitle>
                                <CardDescription>
                                    {parsed_controllers.length} controller section
                                    {parsed_controllers.length === 1 ? '' : 's'} found in the
                                    uploaded file.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-2">
                                {parsed_controllers.map((controller) => (
                                    <Badge key={controller.controller_name} variant="secondary">
                                        {controller.controller_name}
                                    </Badge>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-4">
                                <div>
                                    <CardTitle>AP devices</CardTitle>
                                    <CardDescription>
                                        {allDevices.length} access points extracted from `show ap
                                        database long`.
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => downloadMigrationDevicesCsv(allDevices)}
                                    disabled={allDevices.length === 0}
                                >
                                    <Download className="size-4" />
                                    Download CSV
                                </Button>
                            </CardHeader>
                            <CardContent className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="px-2 py-2 font-medium">Name</th>
                                            <th className="px-2 py-2 font-medium">Serial</th>
                                            <th className="px-2 py-2 font-medium">MAC</th>
                                            <th className="px-2 py-2 font-medium">Controller</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {allDevices.map((device) => (
                                            <tr key={`${device.controller}-${device.serial}`} className="border-b">
                                                <td className="px-2 py-2">{device.name}</td>
                                                <td className="px-2 py-2 font-mono text-xs">
                                                    {device.serial}
                                                </td>
                                                <td className="px-2 py-2 font-mono text-xs">
                                                    {device.mac}
                                                </td>
                                                <td className="px-2 py-2">{device.controller}</td>
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
                                        Switches and ports aggregated from `show ap lldp neighbors`.
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => downloadMigrationLldpCsv(allLldpNeighbors)}
                                    disabled={allLldpNeighbors.length === 0}
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
                                        {allLldpNeighbors.map((neighbor) => (
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
                                    Profiles extracted from `show running-config` with mapped VLAN
                                    names for Central deployment.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div className="grid max-w-md gap-2">
                                    <Label htmlFor="scope_id">Target site</Label>
                                    <Select value={scopeId} onValueChange={setScopeId}>
                                        <SelectTrigger id="scope_id">
                                            <SelectValue placeholder="Select a site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {site_options.map((site) => (
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
                                            Named VLAN profiles will be offset by +200 after WLAN
                                            deploy for this Freezer site.
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
                                                        aria-label="Select all WLAN profiles for deployment"
                                                        onCheckedChange={(checked) =>
                                                            toggleAllProfiles(checked === true)
                                                        }
                                                        disabled={allWlanProfiles.length === 0}
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
                                            {allWlanProfiles.map((profile) => {
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
                                                    <tr
                                                        key={profile.ssid_profile_name}
                                                        className="border-b align-top"
                                                    >
                                                        <td className="px-2 py-2">
                                                            <Checkbox
                                                                id={`deploy-profile-${profile.ssid_profile_name}`}
                                                                checked={selectedProfileNames.has(
                                                                    profile.ssid_profile_name,
                                                                )}
                                                                aria-label={`Deploy ${profile.ssid_profile_name}`}
                                                                onCheckedChange={(checked) =>
                                                                    toggleProfileSelected(
                                                                        profile.ssid_profile_name,
                                                                        checked === true,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2 font-mono text-xs">
                                                            <label
                                                                htmlFor={`deploy-profile-${profile.ssid_profile_name}`}
                                                                className="cursor-pointer"
                                                            >
                                                                {profile.ssid_profile_name}
                                                            </label>
                                                        </td>
                                                        <td className="px-2 py-2">{essid || '—'}</td>
                                                        <td className="px-2 py-2">
                                                            {profile.raw_vlan
                                                                ? `${profile.raw_vlan} → ${profile.vlan_name ?? '—'}`
                                                                : '—'}
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
                                                                    toggleProfileExpanded(
                                                                        profile.ssid_profile_name,
                                                                    )
                                                                }
                                                            >
                                                                {expandedProfiles[
                                                                    profile.ssid_profile_name
                                                                ] ? (
                                                                    <ChevronDown className="size-4" />
                                                                ) : (
                                                                    <ChevronRight className="size-4" />
                                                                )}
                                                            </Button>
                                                            {expandedProfiles[
                                                                profile.ssid_profile_name
                                                            ] && (
                                                                <pre className="mt-2 max-w-xl overflow-x-auto rounded-md bg-muted p-2 text-xs">
                                                                    {JSON.stringify(
                                                                        profile.body,
                                                                        null,
                                                                        2,
                                                                    )}
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
                                        deploying ||
                                        scopeId.trim() === '' ||
                                        selectedWlanProfiles.length === 0
                                    }
                                >
                                    {deploying ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : null}
                                    Deploy {selectedWlanProfiles.length} WLAN profile
                                    {selectedWlanProfiles.length === 1 ? '' : 's'}
                                </Button>

                                {deployStarted && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-base">
                                                Deploy progress
                                            </CardTitle>
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
                                                    Step {deployProgress.current} of{' '}
                                                    {deployProgress.total}
                                                    {deployProgress.percent > 0 &&
                                                        ` (${deployProgress.percent}%)`}
                                                </p>
                                                <p className="text-sm font-medium">
                                                    {deployProgress.message}
                                                </p>
                                                {deployError && (
                                                    <p className="text-destructive text-sm">
                                                        {deployError}
                                                    </p>
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
                                                <span className="text-muted-foreground">
                                                    {result.message}
                                                </span>
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
                                                <span className="text-muted-foreground">
                                                    {result.message}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
