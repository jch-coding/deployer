import { Head, usePage } from '@inertiajs/react';
import { Download, Loader2, RadioTower } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
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
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import {
    exportAps,
    index as ekahauIndex,
    prefixAp,
    renameAp,
    renameApByMac,
} from '@/routes/ekahau';
import type { BreadcrumbItem, SharedData } from '@/types';

type ToolResult = {
    results: Record<string, unknown>;
    download_url: string;
};

type SiteResult = {
    success?: string[];
    error?: string[];
    skipped?: string[];
};

type SiteOption = {
    siteId: string;
    siteName: string;
};

type EkahauIndexProps = {
    site_options: SiteOption[];
    has_current_client: boolean;
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
} & SharedData;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Ekahau', href: ekahauIndex().url },
];

function appendBoolean(formData: FormData, key: string, value: boolean) {
    formData.append(key, value ? '1' : '0');
}

async function postTool(url: string, formData: FormData): Promise<ToolResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            ...csrfHeaders(),
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message =
            payload.message ||
            Object.values(payload.errors ?? {})
                .flat()
                .join(' ') ||
            'Request failed';
        throw new Error(String(message));
    }

    return payload as ToolResult;
}

function ResultSummary({ results }: { results: Record<string, unknown> }) {
    const entries = Object.entries(results).filter(([key]) => key !== 'task');

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3 text-sm">
            {entries.map(([site, value]) => {
                const siteResult = value as SiteResult;
                return (
                    <div key={site} className="rounded-md border p-3">
                        <p className="mb-2 font-medium">{site}</p>
                        {siteResult.success && siteResult.success.length > 0 && (
                            <p className="text-emerald-700 dark:text-emerald-400">
                                Success: {siteResult.success.length}
                            </p>
                        )}
                        {siteResult.skipped && siteResult.skipped.length > 0 && (
                            <p className="text-muted-foreground">
                                Skipped: {siteResult.skipped.length}
                            </p>
                        )}
                        {siteResult.error && siteResult.error.length > 0 && (
                            <div className="mt-1 text-destructive">
                                <p>Errors: {siteResult.error.length}</p>
                                <ul className="mt-1 list-inside list-disc text-xs">
                                    {siteResult.error.slice(0, 8).map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                    {siteResult.error.length > 8 && (
                                        <li>…and {siteResult.error.length - 8} more</li>
                                    )}
                                </ul>
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function ToolOutcome({
    outcome,
    loading,
}: {
    outcome: ToolResult | null;
    loading: boolean;
}) {
    if (loading) {
        return (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Processing…
            </div>
        );
    }

    if (!outcome) {
        return null;
    }

    return (
        <div className="space-y-3 border-t pt-4">
            <Button asChild variant="outline" size="sm">
                <a href={outcome.download_url}>
                    <Download className="size-4" />
                    Download result
                </a>
            </Button>
            <ResultSummary results={outcome.results} />
        </div>
    );
}

function PrefixFields({
    idPrefix,
    mode,
    onModeChange,
    requireMode,
}: {
    idPrefix: string;
    mode: string;
    onModeChange: (value: string) => void;
    requireMode?: boolean;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}-prefix-mode`}>Prefix mode</Label>
                <Select value={mode} onValueChange={onModeChange}>
                    <SelectTrigger id={`${idPrefix}-prefix-mode`}>
                        <SelectValue placeholder="Select mode" />
                    </SelectTrigger>
                    <SelectContent>
                        {!requireMode && <SelectItem value="none">None</SelectItem>}
                        <SelectItem value="flat">Flat prefix</SelectItem>
                        <SelectItem value="template">Template</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            {mode === 'flat' && (
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-flat-prefix`}>Prefix</Label>
                    <Input id={`${idPrefix}-flat-prefix`} name="ap_name_prefix" required />
                </div>
            )}
            {mode === 'template' && (
                <>
                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}-template`}>Template</Label>
                        <Input
                            id={`${idPrefix}-template`}
                            name="ap_name_prefix_template"
                            placeholder="{filename}-{floor}-{custom}"
                            required
                        />
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor={`${idPrefix}-custom`}>Custom value</Label>
                        <Input id={`${idPrefix}-custom`} name="ap_name_prefix_custom" />
                    </div>
                </>
            )}
        </div>
    );
}

export default function EkahauIndex() {
    const {
        site_options,
        has_current_client,
        central_sites_cache,
        central_groups_cache,
    } = usePage<EkahauIndexProps>().props;

    const [renameLoading, setRenameLoading] = useState(false);
    const [renameByMacLoading, setRenameByMacLoading] = useState(false);
    const [exportLoading, setExportLoading] = useState(false);
    const [prefixLoading, setPrefixLoading] = useState(false);

    const [renameOutcome, setRenameOutcome] = useState<ToolResult | null>(null);
    const [renameByMacOutcome, setRenameByMacOutcome] = useState<ToolResult | null>(null);
    const [exportOutcome, setExportOutcome] = useState<ToolResult | null>(null);
    const [prefixOutcome, setPrefixOutcome] = useState<ToolResult | null>(null);

    const [renameLowercase, setRenameLowercase] = useState(false);
    const [macLowercase, setMacLowercase] = useState(false);
    const [mappingSource, setMappingSource] = useState('bssid');
    const [selectedSiteId, setSelectedSiteId] = useState('');
    const [exportPrefixMode, setExportPrefixMode] = useState('none');
    const [prefixMode, setPrefixMode] = useState('flat');

    const selectedSite = useMemo(
        () => site_options.find((site) => site.siteId === selectedSiteId) ?? null,
        [site_options, selectedSiteId],
    );

    const centralMappingBlocked =
        mappingSource === 'central' && (!has_current_client || site_options.length === 0);

    async function handleRename(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        appendBoolean(formData, 'lowercase_ap_names', renameLowercase);
        setRenameLoading(true);
        setRenameOutcome(null);
        try {
            const result = await postTool(renameAp().url, formData);
            setRenameOutcome(result);
            toast.success('Rename complete');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Rename failed');
        } finally {
            setRenameLoading(false);
        }
    }

    async function handleRenameByMac(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (mappingSource === 'central' && !selectedSiteId) {
            toast.error('Select a Central site to pull BSSIDs');
            return;
        }

        const form = event.currentTarget;
        const formData = new FormData(form);
        formData.set('mapping_source', mappingSource);
        appendBoolean(formData, 'lowercase_ap_names', macLowercase);

        if (mappingSource === 'central') {
            formData.set('site_id', selectedSiteId);
            formData.set('site_name', selectedSite?.siteName ?? '');
            formData.delete('mapping_file');
        }

        setRenameByMacLoading(true);
        setRenameByMacOutcome(null);
        try {
            const result = await postTool(renameApByMac().url, formData);
            setRenameByMacOutcome(result);
            toast.success('MAC rename complete');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'MAC rename failed');
        } finally {
            setRenameByMacLoading(false);
        }
    }

    async function handleExport(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        formData.set('prefix_mode', exportPrefixMode);
        setExportLoading(true);
        setExportOutcome(null);
        try {
            const result = await postTool(exportAps().url, formData);
            setExportOutcome(result);
            toast.success('Export complete');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Export failed');
        } finally {
            setExportLoading(false);
        }
    }

    async function handlePrefix(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        formData.set('prefix_mode', prefixMode);
        setPrefixLoading(true);
        setPrefixOutcome(null);
        try {
            const result = await postTool(prefixAp().url, formData);
            setPrefixOutcome(result);
            toast.success('Prefix complete');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Prefix failed');
        } finally {
            setPrefixLoading(false);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ekahau" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-start gap-3">
                    <RadioTower className="mt-1 size-6 text-muted-foreground" />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Ekahau</h1>
                        <p className="text-muted-foreground">
                            Rename, prefix, and export access points from Ekahau `.esx` project
                            files.
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Rename ESX AP</CardTitle>
                            <CardDescription>
                                Map drawing names from an installer workbook to final AP names.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-4" onSubmit={handleRename}>
                                <div className="space-y-2">
                                    <Label htmlFor="rename-esx">ESX file</Label>
                                    <Input
                                        id="rename-esx"
                                        name="esx_file"
                                        type="file"
                                        accept=".esx"
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="rename-excel">Installer Excel</Label>
                                    <Input
                                        id="rename-excel"
                                        name="excel_file"
                                        type="file"
                                        accept=".xlsx,.xls"
                                        required
                                    />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="rename-sheet">Sheet name</Label>
                                        <Input id="rename-sheet" name="sheet_name" required />
                                    </div>
                                    <div className="flex items-end gap-2 pb-2">
                                        <Checkbox
                                            id="rename-lowercase"
                                            checked={renameLowercase}
                                            onCheckedChange={(checked) =>
                                                setRenameLowercase(checked === true)
                                            }
                                        />
                                        <Label htmlFor="rename-lowercase">Lowercase AP names</Label>
                                    </div>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="rename-esx-col">ESX AP name column</Label>
                                        <Input
                                            id="rename-esx-col"
                                            name="esx_ap_name"
                                            placeholder="WAP location # on Drawing"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="rename-ap-col">New AP name column</Label>
                                        <Input
                                            id="rename-ap-col"
                                            name="ap_name"
                                            placeholder="New WAP Name"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="rename-site-col">
                                            Site / floor column (for duplicate names)
                                        </Label>
                                        <Input
                                            id="rename-site-col"
                                            name="site_name"
                                            placeholder="Site Bld Floor"
                                        />
                                    </div>
                                </div>
                                <Button type="submit" disabled={renameLoading}>
                                    {renameLoading && <Loader2 className="size-4 animate-spin" />}
                                    Run rename
                                </Button>
                                <ToolOutcome outcome={renameOutcome} loading={renameLoading} />
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Rename ESX AP by MAC</CardTitle>
                            <CardDescription>
                                Match measured AP MAC suffixes to names from a mapping file or
                                Central site BSSIDs.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-4" onSubmit={handleRenameByMac}>
                                <div className="space-y-2">
                                    <Label htmlFor="mac-esx">ESX file(s)</Label>
                                    <Input
                                        id="mac-esx"
                                        name="esx_files[]"
                                        type="file"
                                        accept=".esx"
                                        multiple
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="mac-source">Mapping source</Label>
                                    <Select value={mappingSource} onValueChange={setMappingSource}>
                                        <SelectTrigger id="mac-source">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="bssid">
                                                BSSID Excel (raw_mac, ap_name)
                                            </SelectItem>
                                            <SelectItem value="csv">CSV</SelectItem>
                                            <SelectItem value="excel">Installer Excel</SelectItem>
                                            <SelectItem value="central">
                                                Central site BSSIDs
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {mappingSource === 'central' ? (
                                    <div className="space-y-3">
                                        {has_current_client ? (
                                            <CentralScopeRefreshButtons
                                                centralSitesCache={central_sites_cache}
                                                centralGroupsCache={central_groups_cache}
                                                reloadOnly={[
                                                    'site_options',
                                                    'central_sites_cache',
                                                    'central_groups_cache',
                                                ]}
                                                layout="compact"
                                            />
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Set a current client to load Central sites and pull
                                                BSSIDs.
                                            </p>
                                        )}
                                        <div className="space-y-2">
                                            <Label htmlFor="mac-central-site">Central site</Label>
                                            <Select
                                                value={selectedSiteId}
                                                onValueChange={setSelectedSiteId}
                                                disabled={!has_current_client || site_options.length === 0}
                                            >
                                                <SelectTrigger id="mac-central-site">
                                                    <SelectValue placeholder="Select a site" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {site_options.map((site) => (
                                                        <SelectItem
                                                            key={site.siteId}
                                                            value={site.siteId}
                                                        >
                                                            {site.siteName}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="mac-mapping">Mapping file</Label>
                                            <Input
                                                id="mac-mapping"
                                                name="mapping_file"
                                                type="file"
                                                accept={
                                                    mappingSource === 'csv'
                                                        ? '.csv'
                                                        : '.xlsx,.xls'
                                                }
                                                required
                                            />
                                        </div>
                                        {mappingSource !== 'bssid' && (
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {mappingSource === 'excel' && (
                                                    <div className="space-y-2 sm:col-span-2">
                                                        <Label htmlFor="mac-sheet">Sheet name</Label>
                                                        <Input
                                                            id="mac-sheet"
                                                            name="sheet_name"
                                                            required
                                                        />
                                                    </div>
                                                )}
                                                <div className="space-y-2">
                                                    <Label htmlFor="mac-col">MAC column</Label>
                                                    <Input id="mac-col" name="ap_mac" required />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="mac-name-col">
                                                        AP name column
                                                    </Label>
                                                    <Input
                                                        id="mac-name-col"
                                                        name="ap_name"
                                                        required
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )}
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="mac-lowercase"
                                        checked={macLowercase}
                                        onCheckedChange={(checked) =>
                                            setMacLowercase(checked === true)
                                        }
                                    />
                                    <Label htmlFor="mac-lowercase">Lowercase AP names</Label>
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        renameByMacLoading ||
                                        centralMappingBlocked ||
                                        (mappingSource === 'central' && selectedSiteId === '')
                                    }
                                >
                                    {renameByMacLoading && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}
                                    Run MAC rename
                                </Button>
                                <ToolOutcome
                                    outcome={renameByMacOutcome}
                                    loading={renameByMacLoading}
                                />
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Export Ekahau APs</CardTitle>
                            <CardDescription>
                                Export AP Name, Model, and Serial columns to Excel.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-4" onSubmit={handleExport}>
                                <div className="space-y-2">
                                    <Label htmlFor="export-esx">ESX file(s)</Label>
                                    <Input
                                        id="export-esx"
                                        name="esx_files[]"
                                        type="file"
                                        accept=".esx"
                                        multiple
                                        required
                                    />
                                </div>
                                <PrefixFields
                                    idPrefix="export"
                                    mode={exportPrefixMode}
                                    onModeChange={setExportPrefixMode}
                                />
                                <Button type="submit" disabled={exportLoading}>
                                    {exportLoading && <Loader2 className="size-4 animate-spin" />}
                                    Export APs
                                </Button>
                                <ToolOutcome outcome={exportOutcome} loading={exportLoading} />
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Prefix ESX AP</CardTitle>
                            <CardDescription>
                                Prepend a flat or templated prefix to AP names inside the ESX
                                file(s).
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-4" onSubmit={handlePrefix}>
                                <div className="space-y-2">
                                    <Label htmlFor="prefix-esx">ESX file(s)</Label>
                                    <Input
                                        id="prefix-esx"
                                        name="esx_files[]"
                                        type="file"
                                        accept=".esx"
                                        multiple
                                        required
                                    />
                                </div>
                                <PrefixFields
                                    idPrefix="prefix"
                                    mode={prefixMode}
                                    onModeChange={setPrefixMode}
                                    requireMode
                                />
                                <Button type="submit" disabled={prefixLoading}>
                                    {prefixLoading && <Loader2 className="size-4 animate-spin" />}
                                    Apply prefix
                                </Button>
                                <ToolOutcome outcome={prefixOutcome} loading={prefixLoading} />
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
