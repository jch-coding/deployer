import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import CnacStaticTagsPicker from '@/components/central/CnacStaticTagsPicker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SearchableSelect } from '@/components/ui/searchable-select';
import {
    greenlake_locations,
    greenlake_service_regions,
} from '@/routes/tasks';

export type CentralSiteOption = {
    scopeId: string;
    name: string;
};

export type GreenLakeTagRow = {
    key: string;
    value: string;
};

export type CatalogueConfigPayload = {
    greenlake_location_id?: string;
    greenlake_application_id?: string;
    greenlake_application_region?: string;
    greenlake_tags?: GreenLakeTagRow[];
    static_tags?: string[];
    site_name?: string;
    site_scope_id?: string;
    network_ipv4_address?: string;
};

type GreenLakeLocationOption = { id: string; name: string };
type GreenLakeServiceRegionOption = {
    application_id: string;
    region: string;
    name: string;
};

type CustomWorkflowCatalogueConfigProps = {
    deploymentId: number;
    selectedSteps: string[];
    centralSites?: CentralSiteOption[];
    centralSitesError?: string | null;
    availableStaticTags?: string[];
    greenLakeLocationId: string;
    onGreenLakeLocationIdChange: (value: string) => void;
    greenLakeServiceKey: string;
    onGreenLakeServiceKeyChange: (value: string) => void;
    greenLakeTags: GreenLakeTagRow[];
    onGreenLakeTagsChange: (tags: GreenLakeTagRow[]) => void;
    centralStaticTags: string[];
    onCentralStaticTagsChange: (tags: string[]) => void;
    networkAliasSiteScopeId: string;
    onNetworkAliasSiteScopeIdChange: (value: string) => void;
    networkAliasIpv4Override: string;
    onNetworkAliasIpv4OverrideChange: (value: string) => void;
};

const selectClassName =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

function defaultNetworkCidrFromSiteName(siteName: string): string | null {
    const match = siteName.trim().match(/^(\d{2})\s*-\s*.+/);
    if (!match) {
        return null;
    }

    return `10.${Number(match[1])}.9.0/24`;
}

export function buildCatalogueConfigPayload(
    selectedSteps: string[],
    state: {
        greenLakeLocationId: string;
        greenLakeServiceKey: string;
        greenLakeTags: GreenLakeTagRow[];
        centralStaticTags: string[];
        networkAliasSiteScopeId: string;
        networkAliasSiteName?: string;
        networkAliasIpv4Override: string;
    },
): CatalogueConfigPayload {
    const payload: CatalogueConfigPayload = {};

    if (selectedSteps.includes('add_to_greenlake_inventory')) {
        if (state.greenLakeLocationId.trim() !== '') {
            payload.greenlake_location_id = state.greenLakeLocationId.trim();
        }
        if (state.greenLakeServiceKey.includes('|')) {
            const sep = state.greenLakeServiceKey.indexOf('|');
            payload.greenlake_application_id = state.greenLakeServiceKey.slice(
                0,
                sep,
            );
            payload.greenlake_application_region =
                state.greenLakeServiceKey.slice(sep + 1);
        }
        const tags = state.greenLakeTags.filter(
            (row) => row.key.trim() !== '',
        );
        if (tags.length > 0) {
            payload.greenlake_tags = tags.map((row) => ({
                key: row.key.trim(),
                value: row.value.trim(),
            }));
        }
    }

    if (selectedSteps.includes('export_mac_to_central_nac')) {
        const tags = state.centralStaticTags
            .map((tag) => tag.trim())
            .filter((tag) => tag !== '');
        if (tags.length > 0) {
            payload.static_tags = tags;
        }
    }

    if (selectedSteps.includes('create_network_alias')) {
        if (state.networkAliasSiteScopeId.trim() !== '') {
            payload.site_scope_id = state.networkAliasSiteScopeId.trim();
        }
        if (state.networkAliasSiteName && state.networkAliasSiteName.trim() !== '') {
            payload.site_name = state.networkAliasSiteName.trim();
        }
        if (state.networkAliasIpv4Override.trim() !== '') {
            payload.network_ipv4_address = state.networkAliasIpv4Override.trim();
        }
    }

    return payload;
}

export default function CustomWorkflowCatalogueConfig({
    deploymentId,
    selectedSteps,
    centralSites = [],
    centralSitesError = null,
    availableStaticTags = [],
    greenLakeLocationId,
    onGreenLakeLocationIdChange,
    greenLakeServiceKey,
    onGreenLakeServiceKeyChange,
    greenLakeTags,
    onGreenLakeTagsChange,
    centralStaticTags,
    onCentralStaticTagsChange,
    networkAliasSiteScopeId,
    onNetworkAliasSiteScopeIdChange,
    networkAliasIpv4Override,
    onNetworkAliasIpv4OverrideChange,
}: CustomWorkflowCatalogueConfigProps) {
    const includesGreenLake = selectedSteps.includes(
        'add_to_greenlake_inventory',
    );
    const includesExportMac = selectedSteps.includes(
        'export_mac_to_central_nac',
    );
    const includesNetworkAlias = selectedSteps.includes('create_network_alias');

    const [greenLakeLocations, setGreenLakeLocations] = useState<
        GreenLakeLocationOption[]
    >([]);
    const [greenLakeLocationsLoading, setGreenLakeLocationsLoading] =
        useState(false);
    const [greenLakeLocationsError, setGreenLakeLocationsError] = useState<
        string | null
    >(null);
    const [greenLakeServiceRegions, setGreenLakeServiceRegions] = useState<
        GreenLakeServiceRegionOption[]
    >([]);
    const [greenLakeServiceRegionsLoading, setGreenLakeServiceRegionsLoading] =
        useState(false);
    const [greenLakeServiceRegionsError, setGreenLakeServiceRegionsError] =
        useState<string | null>(null);

    useEffect(() => {
        if (!includesGreenLake) {
            return;
        }

        let cancelled = false;
        setGreenLakeLocationsLoading(true);
        setGreenLakeLocationsError(null);

        fetch(greenlake_locations.url(deploymentId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const data = (await response.json().catch(() => ({}))) as {
                    locations?: GreenLakeLocationOption[];
                    message?: string;
                };
                if (!response.ok) {
                    throw new Error(
                        data.message ?? 'Failed to load GreenLake locations.',
                    );
                }

                return data.locations ?? [];
            })
            .then((locations) => {
                if (!cancelled) {
                    setGreenLakeLocations(locations);
                }
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setGreenLakeLocations([]);
                    setGreenLakeLocationsError(
                        error instanceof Error
                            ? error.message
                            : 'Failed to load GreenLake locations.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setGreenLakeLocationsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [includesGreenLake, deploymentId]);

    useEffect(() => {
        if (!includesGreenLake) {
            return;
        }

        let cancelled = false;
        setGreenLakeServiceRegionsLoading(true);
        setGreenLakeServiceRegionsError(null);

        fetch(greenlake_service_regions.url(deploymentId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const data = (await response.json().catch(() => ({}))) as {
                    service_regions?: GreenLakeServiceRegionOption[];
                    message?: string;
                };
                if (!response.ok) {
                    throw new Error(
                        data.message ??
                            'Failed to load GreenLake service regions.',
                    );
                }

                return data.service_regions ?? [];
            })
            .then((regions) => {
                if (!cancelled) {
                    setGreenLakeServiceRegions(regions);
                }
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setGreenLakeServiceRegions([]);
                    setGreenLakeServiceRegionsError(
                        error instanceof Error
                            ? error.message
                            : 'Failed to load GreenLake service regions.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setGreenLakeServiceRegionsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [includesGreenLake, deploymentId]);

    const networkAliasSiteOptions = useMemo(
        () =>
            centralSites.map((site) => ({
                value: site.scopeId,
                label: site.name,
            })),
        [centralSites],
    );

    const selectedNetworkAliasSite = useMemo(
        () =>
            centralSites.find(
                (site) => site.scopeId === networkAliasSiteScopeId,
            ) ?? null,
        [centralSites, networkAliasSiteScopeId],
    );

    const networkAliasCidrPreview =
        networkAliasIpv4Override.trim() !== ''
            ? networkAliasIpv4Override.trim()
            : selectedNetworkAliasSite
              ? defaultNetworkCidrFromSiteName(selectedNetworkAliasSite.name)
              : null;

    if (!includesGreenLake && !includesExportMac && !includesNetworkAlias) {
        return null;
    }

    return (
        <div className="space-y-4" data-test="custom-workflow-catalogue-config">
            {includesGreenLake ? (
                <div
                    className="space-y-3 rounded-md border p-3"
                    data-test="greenlake-step-config"
                >
                    <p className="text-sm font-medium">
                        GreenLake inventory options
                    </p>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">
                            Location (optional)
                        </label>
                        <select
                            className={selectClassName}
                            value={greenLakeLocationId}
                            onChange={(e) =>
                                onGreenLakeLocationIdChange(e.target.value)
                            }
                            disabled={greenLakeLocationsLoading}
                            data-test="workflow-greenlake-location-select"
                        >
                            <option value="">
                                {greenLakeLocationsLoading
                                    ? 'Loading locations...'
                                    : greenLakeLocationsError
                                      ? 'Could not load locations'
                                      : greenLakeLocations.length === 0
                                        ? 'No locations available'
                                        : 'No location'}
                            </option>
                            {greenLakeLocations.map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </select>
                        {greenLakeLocationsError ? (
                            <p className="text-xs text-destructive">
                                {greenLakeLocationsError}
                            </p>
                        ) : null}
                    </div>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">
                            Service (optional)
                        </label>
                        <select
                            className={selectClassName}
                            value={greenLakeServiceKey}
                            onChange={(e) =>
                                onGreenLakeServiceKeyChange(e.target.value)
                            }
                            disabled={greenLakeServiceRegionsLoading}
                            data-test="workflow-greenlake-service-select"
                        >
                            <option value="">
                                {greenLakeServiceRegionsLoading
                                    ? 'Loading services...'
                                    : greenLakeServiceRegionsError
                                      ? 'Could not load services'
                                      : greenLakeServiceRegions.length === 0
                                        ? 'No provisioned services available'
                                        : 'No service'}
                            </option>
                            {greenLakeServiceRegions.map((service) => {
                                const value = `${service.application_id}|${service.region}`;

                                return (
                                    <option key={value} value={value}>
                                        {service.name}
                                    </option>
                                );
                            })}
                        </select>
                        {greenLakeServiceRegionsError ? (
                            <p className="text-xs text-destructive">
                                {greenLakeServiceRegionsError}
                            </p>
                        ) : null}
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium">
                                GreenLake tags (optional)
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    onGreenLakeTagsChange([
                                        ...greenLakeTags,
                                        { key: '', value: '' },
                                    ])
                                }
                            >
                                <Plus className="mr-1 size-3" />
                                Add tag
                            </Button>
                        </div>
                        {greenLakeTags.map((row, index) => (
                            <div
                                key={`gl-tag-${index}`}
                                className="flex items-center gap-2"
                            >
                                <Input
                                    placeholder="Key"
                                    value={row.key}
                                    onChange={(e) => {
                                        const next = [...greenLakeTags];
                                        next[index] = {
                                            ...row,
                                            key: e.target.value,
                                        };
                                        onGreenLakeTagsChange(next);
                                    }}
                                />
                                <Input
                                    placeholder="Value"
                                    value={row.value}
                                    onChange={(e) => {
                                        const next = [...greenLakeTags];
                                        next[index] = {
                                            ...row,
                                            value: e.target.value,
                                        };
                                        onGreenLakeTagsChange(next);
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        onGreenLakeTagsChange(
                                            greenLakeTags.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                    aria-label="Remove tag"
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}

            {includesExportMac ? (
                <div
                    className="space-y-2 rounded-md border p-3"
                    data-test="cnac-mac-step-config"
                >
                    <p className="text-sm font-medium">
                        Central NAC static tags (optional)
                    </p>
                    <CnacStaticTagsPicker
                        value={centralStaticTags}
                        onChange={onCentralStaticTagsChange}
                        availableTags={availableStaticTags}
                    />
                </div>
            ) : null}

            {includesNetworkAlias ? (
                <div
                    className="space-y-3 rounded-md border p-3"
                    data-test="network-alias-step-config"
                >
                    <p className="text-sm font-medium">Network alias</p>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">
                            Central site
                        </label>
                        <SearchableSelect
                            value={networkAliasSiteScopeId || undefined}
                            onValueChange={onNetworkAliasSiteScopeIdChange}
                            options={networkAliasSiteOptions}
                            placeholder={
                                centralSitesError
                                    ? 'Could not load sites'
                                    : centralSites.length === 0
                                      ? 'No sites available'
                                      : 'Select a site'
                            }
                            disabled={
                                centralSitesError !== null ||
                                centralSites.length === 0
                            }
                            className="w-full"
                            aria-label="Central site for network alias"
                            data-test="workflow-network-alias-site-select"
                        />
                        {centralSitesError ? (
                            <p className="text-xs text-destructive">
                                {centralSitesError}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                Creates LOCAL alias BVSD-VIVI-SUBNET once for
                                this workflow.
                            </p>
                        )}
                    </div>
                    <div className="space-y-1">
                        <label className="text-sm font-medium">
                            Network IPv4 address (optional)
                        </label>
                        <Input
                            type="text"
                            placeholder="e.g. 10.7.9.0/24"
                            value={networkAliasIpv4Override}
                            onChange={(e) =>
                                onNetworkAliasIpv4OverrideChange(e.target.value)
                            }
                            data-test="workflow-network-alias-ipv4-override"
                        />
                        <p className="text-xs text-muted-foreground">
                            {`Leave blank to derive 10.{nn}.9.0/24 from the site name.`}
                            {networkAliasCidrPreview
                                ? ` Will use ${networkAliasCidrPreview}.`
                                : selectedNetworkAliasSite
                                  ? ' Selected site name does not match nn - …; enter an override.'
                                  : ''}
                        </p>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
