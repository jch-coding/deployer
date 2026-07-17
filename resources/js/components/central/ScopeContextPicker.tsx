import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
import type { CentralApiScopeOption } from '@/types/central-api';

export type ScopeContextType = 'site' | 'group' | 'site_collection';

type ScopeContextPickerProps = {
    scopeSites: CentralApiScopeOption[];
    scopeGroups: CentralApiScopeOption[];
    scopeSiteCollections: CentralApiScopeOption[];
    scopeSitesError: string | null;
    scopeGroupsError: string | null;
    scopeSiteCollectionsError: string | null;
    centralSitesCache: CentralScopeCacheMeta;
    centralGroupsCache: CentralScopeGroupsCacheMeta;
    onApply: (scopeId: string) => void;
};

const SCOPE_TYPE_LABELS: Record<ScopeContextType, string> = {
    site: 'Site',
    group: 'Group',
    site_collection: 'Site collection',
};

function optionKey(option: CentralApiScopeOption): string {
    return `${option.scopeName}::${option.scopeId}`;
}

export default function ScopeContextPicker({
    scopeSites,
    scopeGroups,
    scopeSiteCollections,
    scopeSitesError,
    scopeGroupsError,
    scopeSiteCollectionsError,
    centralSitesCache,
    centralGroupsCache,
    onApply,
}: ScopeContextPickerProps) {
    const [scopeType, setScopeType] = useState<ScopeContextType>('site');
    const [selectedKey, setSelectedKey] = useState<string>('');

    const optionsForType = useMemo((): CentralApiScopeOption[] => {
        switch (scopeType) {
            case 'site':
                return scopeSites;
            case 'group':
                return scopeGroups;
            case 'site_collection':
                return scopeSiteCollections;
        }
    }, [scopeGroups, scopeSiteCollections, scopeSites, scopeType]);

    const selectedOption = useMemo(
        () => optionsForType.find((option) => optionKey(option) === selectedKey) ?? null,
        [optionsForType, selectedKey],
    );

    const typeError = useMemo((): string | null => {
        switch (scopeType) {
            case 'site':
                return scopeSitesError;
            case 'group':
                return scopeGroupsError;
            case 'site_collection':
                return scopeSiteCollectionsError;
        }
    }, [scopeGroupsError, scopeSiteCollectionsError, scopeSitesError, scopeType]);

    const handleTypeChange = (nextType: ScopeContextType) => {
        setScopeType(nextType);
        setSelectedKey('');
    };

    const canApply =
        selectedOption !== null && selectedOption.scopeId.trim() !== '';

    return (
        <div className="rounded-md border p-4">
            <h3 className="mb-3 text-sm font-medium">Scope context</h3>
            <div className="flex flex-col gap-3">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="scope-type-select">Scope type</Label>
                        <Select
                            value={scopeType}
                            onValueChange={(value) =>
                                handleTypeChange(value as ScopeContextType)
                            }
                        >
                            <SelectTrigger id="scope-type-select" className="w-full">
                                <SelectValue placeholder="Select scope type" />
                            </SelectTrigger>
                            <SelectContent>
                                {(Object.keys(SCOPE_TYPE_LABELS) as ScopeContextType[]).map(
                                    (type) => (
                                        <SelectItem key={type} value={type}>
                                            {SCOPE_TYPE_LABELS[type]}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="scope-select">Select scope</Label>
                        <Select
                            value={selectedKey || undefined}
                            onValueChange={setSelectedKey}
                            disabled={optionsForType.length === 0}
                        >
                            <SelectTrigger id="scope-select" className="w-full">
                                <SelectValue placeholder="Select a scope…" />
                            </SelectTrigger>
                            <SelectContent>
                                {optionsForType.map((option) => (
                                    <SelectItem
                                        key={optionKey(option)}
                                        value={optionKey(option)}
                                        disabled={option.scopeId.trim() === ''}
                                    >
                                        <span className="flex flex-col gap-0.5">
                                            <span>{option.scopeName}</span>
                                            {option.scopeId.trim() !== '' && (
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {option.scopeId}
                                                </span>
                                            )}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {typeError && (
                    <p className="text-muted-foreground text-sm">{typeError}</p>
                )}

                {scopeType === 'site_collection' && scopeSiteCollectionsError && (
                    <p className="text-muted-foreground text-xs">
                        Reload this page to retry loading site collections from Central.
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={!canApply}
                        onClick={() => {
                            if (selectedOption) {
                                onApply(selectedOption.scopeId.trim());
                            }
                        }}
                    >
                        Apply scope
                    </Button>
                    <CentralScopeRefreshButtons
                        layout="compact"
                        centralSitesCache={centralSitesCache}
                        centralGroupsCache={centralGroupsCache}
                        reloadOnly={[
                            'scope_sites',
                            'scope_groups',
                            'scope_site_collections',
                            'scope_sites_error',
                            'scope_groups_error',
                            'scope_site_collections_error',
                            'central_sites_cache',
                            'central_groups_cache',
                        ]}
                    />
                </div>
            </div>
        </div>
    );
}
