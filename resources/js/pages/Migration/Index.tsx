import { Head, useForm, usePage } from '@inertiajs/react';
import { FileUp, Loader2, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import ControllerMigrationSection from '@/pages/Migration/ControllerMigrationSection';
import { type DeviceGroupOption } from '@/pages/Migration/CreateDeploymentFromDevicesDialog';
import {
    type DeployResult,
    type NamedVlanDeployResult,
    type ParsedController,
    type ScopeOption,
    type SiteOption,
} from '@/pages/Migration/migration-types';
import { index as clientsIndex } from '@/routes/clients';
import { index as migrationsIndex, parse as migrationsParse } from '@/routes/migrations';
import type { BreadcrumbItem, SharedData } from '@/types';

type MigrationIndexProps = {
    site_options: SiteOption[];
    device_group_options: DeviceGroupOption[];
    site_collection_options: ScopeOption[];
    site_collection_options_error?: string | null;
    device_function_options: string[];
    parsed_controllers: ParsedController[];
    deploy_results: DeployResult[];
    named_vlan_deploy_results: NamedVlanDeployResult[];
    selected_scope_id?: string;
    last_created_deployment?: { name: string; device_count: number } | null;
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
} & SharedData;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Migrations', href: migrationsIndex().url },
];

export default function Index() {
    const {
        current_client,
        site_options,
        device_group_options = [],
        site_collection_options = [],
        site_collection_options_error = null,
        device_function_options = [],
        parsed_controllers,
        central_sites_cache,
        central_groups_cache,
    } = usePage<MigrationIndexProps>().props;

    const [selectedControllerName, setSelectedControllerName] = useState(
        () => parsed_controllers[0]?.controller_name ?? '',
    );

    const parseForm = useForm<{ config_file: File | null }>({
        config_file: null,
    });

    useEffect(() => {
        if (parsed_controllers.length === 0) {
            setSelectedControllerName('');

            return;
        }

        const stillPresent = parsed_controllers.some(
            (controller) => controller.controller_name === selectedControllerName,
        );

        if (!stillPresent) {
            setSelectedControllerName(parsed_controllers[0].controller_name);
        }
    }, [parsed_controllers, selectedControllerName]);

    const selectedController = useMemo(
        () =>
            parsed_controllers.find(
                (controller) => controller.controller_name === selectedControllerName,
            ) ?? null,
        [parsed_controllers, selectedControllerName],
    );

    const handleParseSubmit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!parseForm.data.config_file) {
            toast.error('Please select a config file to upload');

            return;
        }

        parseForm.post(migrationsParse().url, {
            forceFormData: true,
            onSuccess: () => toast.success('Config file parsed successfully'),
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                toast.error(
                    typeof firstError === 'string'
                        ? firstError
                        : 'Failed to parse config file',
                );
            },
        });
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
                        reloadOnly={[
                            'site_options',
                            'device_group_options',
                            'central_sites_cache',
                            'central_groups_cache',
                        ]}
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
                    <Card>
                        <CardHeader>
                            <CardTitle>Controllers</CardTitle>
                            <CardDescription>
                                {parsed_controllers.length} controller
                                {parsed_controllers.length === 1 ? '' : 's'} found in the uploaded
                                file. Select a controller to load its migration cards.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid max-w-xl gap-2">
                                <Label htmlFor="selected-controller">Controller</Label>
                                <Select
                                    value={selectedControllerName}
                                    onValueChange={setSelectedControllerName}
                                >
                                    <SelectTrigger id="selected-controller">
                                        <SelectValue placeholder="Select a controller" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {parsed_controllers.map((controller) => (
                                            <SelectItem
                                                key={controller.controller_name}
                                                value={controller.controller_name}
                                            >
                                                {controller.controller_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {selectedController && (
                    <ControllerMigrationSection
                        key={selectedController.controller_name}
                        controller={selectedController}
                        siteOptions={site_options}
                        groupOptions={device_group_options}
                        siteCollectionOptions={site_collection_options}
                        siteCollectionOptionsError={site_collection_options_error}
                        deviceFunctionOptions={device_function_options}
                        parsedControllers={parsed_controllers}
                    />
                )}
            </div>
        </AppLayout>
    );
}
