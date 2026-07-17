import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { refresh as refreshGroups } from '@/routes/central-scope-cache/groups';
import { refresh as refreshSites } from '@/routes/central-scope-cache/sites';

export type CentralScopeCacheMeta = {
    refreshed_at?: string | null;
    error?: string | null;
};

export type CentralScopeGroupsCacheMeta = CentralScopeCacheMeta & {
    classic_error?: string | null;
};

type CentralScopeRefreshButtonsProps = {
    centralSitesCache: CentralScopeCacheMeta;
    centralGroupsCache: CentralScopeGroupsCacheMeta;
    reloadOnly?: string[];
    layout?: 'default' | 'compact';
    className?: string;
};

export function formatRefreshedAt(iso: string | null | undefined): string {
    if (!iso) {
        return 'Not refreshed yet';
    }

    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function ScopeRefreshButton({
    label,
    refreshingLabel,
    refreshedAt,
    isRefreshing,
    onClick,
    testId,
    layout,
}: {
    label: string;
    refreshingLabel: string;
    refreshedAt?: string | null;
    isRefreshing: boolean;
    onClick: () => void;
    testId: string;
    layout: 'default' | 'compact';
}) {
    const tooltipText = `Last refreshed ${formatRefreshedAt(refreshedAt)}`;

    if (layout === 'compact') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onClick}
                        disabled={isRefreshing}
                        data-test={testId}
                        className="gap-2"
                    >
                        <RefreshCw
                            className={`size-4 shrink-0 ${isRefreshing ? 'animate-spin' : ''}`}
                            aria-hidden
                        />
                        {isRefreshing ? refreshingLabel : label}
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top">{tooltipText}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onClick}
            disabled={isRefreshing}
            data-test={testId}
            className="h-auto min-h-9 flex-col items-start gap-0.5 px-3 py-2 text-left"
        >
            <span className="inline-flex items-center gap-2 text-sm font-medium">
                <RefreshCw
                    className={`size-4 shrink-0 ${isRefreshing ? 'animate-spin' : ''}`}
                    aria-hidden
                />
                {isRefreshing ? refreshingLabel : label}
            </span>
            <span className="pl-6 text-xs font-normal text-muted-foreground">
                {tooltipText}
            </span>
        </Button>
    );
}

export default function CentralScopeRefreshButtons({
    centralSitesCache,
    centralGroupsCache,
    reloadOnly = [
        'central_sites_cache',
        'central_groups_cache',
        'central_sites',
        'central_sites_error',
        'central_device_groups',
        'central_device_groups_error',
        'device_group_options',
        'classic_device_groups_error',
        'site_options',
        'central_error',
    ],
    layout = 'default',
    className,
}: CentralScopeRefreshButtonsProps) {
    const [refreshingSites, setRefreshingSites] = useState(false);
    const [refreshingGroups, setRefreshingGroups] = useState(false);

    const handleRefreshSites = () => {
        setRefreshingSites(true);
        router.post(
            refreshSites.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: reloadOnly });
                },
                onFinish: () => setRefreshingSites(false),
            },
        );
    };

    const handleRefreshGroups = () => {
        setRefreshingGroups(true);
        router.post(
            refreshGroups.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: reloadOnly });
                },
                onFinish: () => setRefreshingGroups(false),
            },
        );
    };

    return (
        <div className={cn('flex flex-wrap gap-2', className)}>
            <ScopeRefreshButton
                layout={layout}
                label={layout === 'compact' ? 'Sites' : 'Refresh sites'}
                refreshingLabel={
                    layout === 'compact' ? 'Sites…' : 'Refreshing sites…'
                }
                refreshedAt={centralSitesCache.refreshed_at}
                isRefreshing={refreshingSites}
                onClick={handleRefreshSites}
                testId="refresh-central-sites-button"
            />
            <ScopeRefreshButton
                layout={layout}
                label={layout === 'compact' ? 'Groups' : 'Refresh groups'}
                refreshingLabel={
                    layout === 'compact' ? 'Groups…' : 'Refreshing groups…'
                }
                refreshedAt={centralGroupsCache.refreshed_at}
                isRefreshing={refreshingGroups}
                onClick={handleRefreshGroups}
                testId="refresh-central-groups-button"
            />
        </div>
    );
}
