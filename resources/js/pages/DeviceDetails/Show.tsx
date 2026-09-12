import { Link, usePage } from '@inertiajs/react';
import { Camera, Download, Eye, EyeOff, Loader2, RotateCcw, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
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
import { csrfHeaders } from '@/lib/csrf';
import { clearMacAddressTableCache } from '@/lib/mac-address-table-cache';
import { clearDatapathSessionTableCache } from '@/lib/datapath-session-table-cache';
import { formatDeviceTitle } from '@/lib/device-label';
import { downloadAllSwitchInterfacesCsv } from '@/lib/switch-interfaces-csv';
import { filterSwitchInterfacesBySearchAll } from '@/lib/switch-interfaces-table-filters';
import { isAccessPointDevice } from '@/lib/is-access-point';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex } from '@/routes/device-details';
import {
    index as indexSnapshotsRoute,
    store as storeSnapshotsRoute,
} from '@/routes/device-details/snapshots';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeviceDetailsPayload = SwitchDetailsPayload & {
    device_type: string;
    device_function: string;
};

type SnapshotSummary = {
    serial: string;
    device_name: string;
    captured_at: string | null;
};

type SnapshotDevice = SwitchDetailsPayload & {
    device_type: string;
    device_function: string;
    captured_at: string | null;
};

type DeviceDetailsShowProps = {
    devices: DeviceDetailsPayload[];
    snapshot_summaries: SnapshotSummary[];
} & SharedData;

function isAccessPoint(device: DeviceDetailsPayload): boolean {
    return isAccessPointDevice(device);
}

export default function Show() {
    const {
        current_client,
        devices,
        snapshot_summaries: initialSnapshotSummaries = [],
    } = usePage<DeviceDetailsShowProps>().props;
    const [searchAllQuery, setSearchAllQuery] = useState('');
    const [snapshotSummaries, setSnapshotSummaries] = useState<SnapshotSummary[]>(
        initialSnapshotSummaries,
    );
    const [snapshotSaving, setSnapshotSaving] = useState(false);
    const [snapshotsVisible, setSnapshotsVisible] = useState(false);
    const [snapshotLoading, setSnapshotLoading] = useState(false);
    const [snapshotDevices, setSnapshotDevices] = useState<SnapshotDevice[]>([]);
    const [snapshotSearchAllQuery, setSnapshotSearchAllQuery] = useState('');
    const [snapshotError, setSnapshotError] = useState<string | null>(null);

    const clientCacheKey = String(current_client?.id ?? 'none');
    const previousClientCacheKeyRef = useRef(clientCacheKey);

    useEffect(() => {
        setSnapshotSummaries(initialSnapshotSummaries);
    }, [initialSnapshotSummaries]);

    useEffect(() => {
        if (previousClientCacheKeyRef.current === clientCacheKey) {
            return;
        }

        previousClientCacheKeyRef.current = clientCacheKey;
        clearMacAddressTableCache();
        clearDatapathSessionTableCache();
        setSnapshotsVisible(false);
        setSnapshotDevices([]);
        setSnapshotSearchAllQuery('');
        setSnapshotError(null);
    }, [clientCacheKey]);

    const accessPoints = useMemo(() => devices.filter(isAccessPoint), [devices]);
    const switches = useMemo(() => devices.filter((device) => !isAccessPoint(device)), [devices]);

    const snapshotSummaryBySerial = useMemo(() => {
        const map = new Map<string, SnapshotSummary>();
        for (const summary of snapshotSummaries) {
            map.set(summary.serial, summary);
        }

        return map;
    }, [snapshotSummaries]);

    const switchesWithSnapshots = useMemo(
        () => switches.filter((device) => snapshotSummaryBySerial.has(device.serial)),
        [snapshotSummaryBySerial, switches],
    );

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

    const hasActiveSnapshotSearchAll = snapshotSearchAllQuery.trim() !== '';

    const visibleSnapshotDevices = useMemo(() => {
        if (!hasActiveSnapshotSearchAll) {
            return snapshotDevices;
        }

        return snapshotDevices.filter(
            (device) =>
                filterSwitchInterfacesBySearchAll(device.interfaces, snapshotSearchAllQuery)
                    .length > 0,
        );
    }, [hasActiveSnapshotSearchAll, snapshotDevices, snapshotSearchAllQuery]);

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

    const loadSnapshots = useCallback(async () => {
        if (switches.length === 0) {
            setSnapshotDevices([]);
            return;
        }

        setSnapshotLoading(true);
        setSnapshotError(null);

        try {
            const response = await fetch(
                indexSnapshotsRoute.url({
                    query: {
                        serials: switches.map((device) => device.serial),
                    },
                }),
                {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                },
            );

            const body = (await response.json().catch(() => null)) as {
                message?: string;
                snapshots?: Array<{
                    serial: string;
                    device_name: string;
                    device_type: string;
                    device_function: string;
                    interfaces: SwitchDetailsPayload['interfaces'];
                    captured_at: string | null;
                }>;
            } | null;

            if (!response.ok) {
                throw new Error(body?.message ?? 'Failed to load saved snapshots.');
            }

            const loaded = (body?.snapshots ?? []).map((snapshot) => ({
                serial: snapshot.serial,
                device_name: snapshot.device_name,
                device_type: snapshot.device_type,
                device_function: snapshot.device_function,
                interfaces: snapshot.interfaces ?? [],
                central_error: null,
                captured_at: snapshot.captured_at,
            }));

            setSnapshotDevices(loaded);
            setSnapshotSummaries((prev) => {
                const next = new Map(prev.map((item) => [item.serial, item]));
                for (const snapshot of loaded) {
                    next.set(snapshot.serial, {
                        serial: snapshot.serial,
                        device_name: snapshot.device_name,
                        captured_at: snapshot.captured_at,
                    });
                }

                return Array.from(next.values());
            });
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Failed to load saved snapshots.';
            setSnapshotError(message);
            toast.error(message);
        } finally {
            setSnapshotLoading(false);
        }
    }, [switches]);

    const handleSaveSnapshot = useCallback(async () => {
        if (switches.length === 0 || snapshotSaving) {
            return;
        }

        const willOverwrite = switches.some((device) =>
            snapshotSummaryBySerial.has(device.serial),
        );

        if (
            willOverwrite &&
            !window.confirm(
                'A snapshot already exists for one or more selected devices. Saving will overwrite those snapshots. Continue?',
            )
        ) {
            return;
        }

        setSnapshotSaving(true);

        try {
            const response = await fetch(storeSnapshotsRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    devices: switches.map((device) => ({
                        serial: device.serial,
                        device_name: device.device_name,
                        device_type: device.device_type,
                        device_function: device.device_function,
                        central_error: device.central_error,
                        interfaces: device.interfaces,
                    })),
                }),
            });

            const body = (await response.json().catch(() => null)) as {
                message?: string;
                snapshots?: SnapshotSummary[];
            } | null;

            if (!response.ok) {
                throw new Error(body?.message ?? 'Failed to save interface snapshot.');
            }

            const saved = body?.snapshots ?? [];
            setSnapshotSummaries((prev) => {
                const next = new Map(prev.map((item) => [item.serial, item]));
                for (const snapshot of saved) {
                    next.set(snapshot.serial, snapshot);
                }

                return Array.from(next.values());
            });

            toast.success(body?.message ?? 'Interface snapshot saved.');

            if (snapshotsVisible) {
                await loadSnapshots();
            }
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Failed to save interface snapshot.',
            );
        } finally {
            setSnapshotSaving(false);
        }
    }, [loadSnapshots, snapshotSaving, snapshotSummaryBySerial, snapshotsVisible, switches]);

    const handleToggleSnapshots = useCallback(async () => {
        if (snapshotsVisible) {
            setSnapshotsVisible(false);
            return;
        }

        setSnapshotsVisible(true);
        await loadSnapshots();
    }, [loadSnapshots, snapshotsVisible]);

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
                            <>
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
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="gap-2"
                                    disabled={snapshotSaving}
                                    onClick={() => void handleSaveSnapshot()}
                                    data-test="device-details-save-snapshot"
                                >
                                    {snapshotSaving ? (
                                        <Loader2 className="size-4 animate-spin" aria-hidden />
                                    ) : (
                                        <Camera className="size-4" aria-hidden />
                                    )}
                                    Snapshot
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="gap-2"
                                    disabled={
                                        switchesWithSnapshots.length === 0 || snapshotLoading
                                    }
                                    onClick={() => void handleToggleSnapshots()}
                                    data-test="device-details-show-snapshot"
                                >
                                    {snapshotLoading ? (
                                        <Loader2 className="size-4 animate-spin" aria-hidden />
                                    ) : snapshotsVisible ? (
                                        <EyeOff className="size-4" aria-hidden />
                                    ) : (
                                        <Eye className="size-4" aria-hidden />
                                    )}
                                    {snapshotsVisible ? 'Hide saved snapshot' : 'Show saved snapshot'}
                                </Button>
                            </>
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

                {snapshotsVisible ? (
                    <section
                        className="mt-12 border-t border-border pt-8"
                        data-test="device-details-snapshot-section"
                    >
                        <div className="mb-4">
                            <h2 className="text-2xl font-semibold">Saved snapshot</h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Interface state previously saved for the selected switches.
                            </p>
                        </div>

                        {snapshotError ? (
                            <div
                                className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                                role="alert"
                                data-test="device-details-snapshot-error"
                            >
                                {snapshotError}
                            </div>
                        ) : null}

                        <div className="relative mb-4 max-w-md">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                value={snapshotSearchAllQuery}
                                onChange={(event) =>
                                    setSnapshotSearchAllQuery(event.target.value)
                                }
                                placeholder="Search all by Neighbour Serial or Native VLAN"
                                className="pl-9"
                                aria-label="Search saved snapshots by Neighbour Serial or Native VLAN"
                                data-test="device-details-snapshot-search-all"
                            />
                        </div>

                        {snapshotLoading ? (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-snapshot-loading"
                            >
                                Loading saved snapshots…
                            </p>
                        ) : null}

                        {!snapshotLoading &&
                        hasActiveSnapshotSearchAll &&
                        visibleSnapshotDevices.length === 0 ? (
                            <p
                                className="mt-4 text-center text-sm text-muted-foreground"
                                data-test="device-details-snapshot-search-all-empty"
                            >
                                No saved snapshots match the current search.
                            </p>
                        ) : null}

                        {!snapshotLoading
                            ? switches.map((device) => {
                                  const snapshot = snapshotDevices.find(
                                      (item) => item.serial === device.serial,
                                  );

                                  if (!snapshot) {
                                      if (hasActiveSnapshotSearchAll) {
                                          return null;
                                      }

                                      return (
                                          <div
                                              key={`snapshot-missing-${device.serial}`}
                                              className="mt-8 border-t border-border pt-8 first:mt-0 first:border-t-0 first:pt-0"
                                              data-test="device-details-snapshot-missing"
                                          >
                                              <h3 className="text-xl font-semibold">
                                                  {formatDeviceTitle(
                                                      device.device_name,
                                                      device.serial,
                                                  )}
                                              </h3>
                                              <p className="mt-2 text-sm text-muted-foreground">
                                                  No saved snapshot for this device.
                                              </p>
                                          </div>
                                      );
                                  }

                                  if (
                                      hasActiveSnapshotSearchAll &&
                                      !visibleSnapshotDevices.some(
                                          (item) => item.serial === snapshot.serial,
                                      )
                                  ) {
                                      return null;
                                  }

                                  return (
                                      <SwitchInterfacesPanel
                                          key={`snapshot-${snapshot.serial}`}
                                          switchDetails={snapshot}
                                          searchQuery={snapshotSearchAllQuery}
                                          snapshotMode
                                          capturedAt={snapshot.captured_at}
                                      />
                                  );
                              })
                            : null}
                    </section>
                ) : null}
            </div>
        </AppLayout>
    );
}
