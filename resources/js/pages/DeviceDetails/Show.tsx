import { Link, usePage } from '@inertiajs/react';
import { Download, RotateCcw, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AccessPointDetailsPanel, {
    type AccessPointDetailsPayload,
} from '@/components/device-details/AccessPointDetailsPanel';
import RebootAccessPointsDialog from '@/components/device-details/RebootAccessPointsDialog';
import SwitchInterfacesPanel, {
    type SwitchDetailsPayload,
} from '@/components/device-details/SwitchInterfacesPanel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { clearMacAddressTableCache } from '@/lib/mac-address-table-cache';
import { clearDatapathSessionTableCache } from '@/lib/datapath-session-table-cache';
import { formatDeviceTitle } from '@/lib/device-label';
import { downloadAllSwitchInterfacesCsv } from '@/lib/switch-interfaces-csv';
import { filterSwitchInterfacesBySearchAll } from '@/lib/switch-interfaces-table-filters';
import { isAccessPointDevice } from '@/lib/is-access-point';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex } from '@/routes/device-details';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeviceDetailsPayload = SwitchDetailsPayload & {
    device_type: string;
    device_function: string;
};

type DeviceDetailsShowProps = {
    devices: DeviceDetailsPayload[];
} & SharedData;

function isAccessPoint(device: DeviceDetailsPayload): boolean {
    return isAccessPointDevice(device);
}

export default function Show() {
    const { current_client, devices } = usePage<DeviceDetailsShowProps>().props;
    const [searchAllQuery, setSearchAllQuery] = useState('');

    const clientCacheKey = String(current_client?.id ?? 'none');
    const previousClientCacheKeyRef = useRef(clientCacheKey);

    useEffect(() => {
        if (previousClientCacheKeyRef.current === clientCacheKey) {
            return;
        }

        previousClientCacheKeyRef.current = clientCacheKey;
        clearMacAddressTableCache();
        clearDatapathSessionTableCache();
    }, [clientCacheKey]);

    const accessPoints = useMemo(() => devices.filter(isAccessPoint), [devices]);
    const switches = useMemo(() => devices.filter((device) => !isAccessPoint(device)), [devices]);

    const hasActiveSearchAll = searchAllQuery.trim() !== '';

    const visibleDevices = useMemo(() => {
        if (!hasActiveSearchAll) {
            return devices;
        }

        return devices.filter((device) => {
            if (isAccessPoint(device)) {
                return false;
            }

            return filterSwitchInterfacesBySearchAll(device.interfaces, searchAllQuery).length > 0;
        });
    }, [devices, hasActiveSearchAll, searchAllQuery]);

    const exportableSwitches = useMemo(() => {
        return switches
            .map((item) => ({
                switchName: formatDeviceTitle(item.device_name, item.serial),
                interfaces: filterSwitchInterfacesBySearchAll(item.interfaces, searchAllQuery),
            }))
            .filter((group) => group.interfaces.length > 0);
    }, [searchAllQuery, switches]);

    const exportableInterfaceCount = useMemo(
        () => exportableSwitches.reduce((sum, item) => sum + item.interfaces.length, 0),
        [exportableSwitches],
    );

    const pageTitle = useMemo(() => {
        if (devices.length === 0) {
            return 'Device Details';
        }
        if (devices.length === 1) {
            return formatDeviceTitle(devices[0].device_name, devices[0].serial);
        }

        if (accessPoints.length === devices.length) {
            return `${devices.length} access points`;
        }

        if (switches.length === devices.length) {
            return `${devices.length} switches`;
        }

        return `${devices.length} devices`;
    }, [accessPoints.length, devices, switches.length]);

    const subtitle = useMemo(() => {
        if (devices.length <= 1) {
            return null;
        }

        if (accessPoints.length === devices.length) {
            return `Viewing details for ${devices.length} selected access points.`;
        }

        if (switches.length === devices.length) {
            return `Viewing interfaces for ${devices.length} selected switches.`;
        }

        return `Viewing details for ${switches.length} switch${switches.length === 1 ? '' : 'es'} and ${accessPoints.length} access point${accessPoints.length === 1 ? '' : 's'}.`;
    }, [accessPoints.length, devices.length, switches.length]);

    const accessPointSerials = useMemo(
        () => accessPoints.map((device) => device.serial),
        [accessPoints],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Device Details',
            href: deviceDetailsIndex().url,
        },
        {
            title: pageTitle,
            href: '#',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-3xl font-semibold" data-test="device-details-show-title">
                            {pageTitle}
                        </h1>
                        {subtitle ? (
                            <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={deviceDetailsIndex().url} data-test="device-details-back-link">
                                Back to search
                            </Link>
                        </Button>
                        {switches.length > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="gap-2"
                                disabled={exportableInterfaceCount === 0}
                                onClick={() =>
                                    downloadAllSwitchInterfacesCsv(exportableSwitches)
                                }
                                data-test="device-details-export-all-csv"
                            >
                                <Download className="size-4" aria-hidden />
                                Export All CSV
                            </Button>
                        ) : null}
                        {accessPoints.length >= 1 ? (
                            <RebootAccessPointsDialog
                                serials={accessPointSerials}
                                trigger={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="gap-2"
                                        data-test="device-details-reboot-selected"
                                    >
                                        <RotateCcw className="size-4" aria-hidden />
                                        {accessPoints.length === 1
                                            ? 'Reboot AP'
                                            : 'Reboot selected APs'}
                                    </Button>
                                }
                            />
                        ) : null}
                    </div>
                </div>

                <div className="relative mt-4 max-w-md">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        type="search"
                        value={searchAllQuery}
                        onChange={(event) => setSearchAllQuery(event.target.value)}
                        placeholder="Search all by Neighbour Serial or Native VLAN"
                        className="pl-9"
                        aria-label="Search all by Neighbour Serial or Native VLAN"
                        data-test="device-details-search-all"
                    />
                </div>

                {hasActiveSearchAll && visibleDevices.length === 0 ? (
                    <p
                        className="mt-8 text-center text-sm text-muted-foreground"
                        data-test="device-details-search-all-empty"
                    >
                        No devices match the current search.
                    </p>
                ) : null}

                {visibleDevices.map((device) =>
                    isAccessPoint(device) ? (
                        <AccessPointDetailsPanel
                            key={device.serial}
                            accessPoint={device as AccessPointDetailsPayload}
                        />
                    ) : (
                        <SwitchInterfacesPanel
                            key={device.serial}
                            switchDetails={device}
                            searchQuery={searchAllQuery}
                        />
                    ),
                )}
            </div>
        </AppLayout>
    );
}
