import type { ColumnDef } from '@tanstack/react-table';
import { Download, Loader2, Wifi } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { csrfHeaders } from '@/lib/csrf';
import { downloadBssidsCsv, type BssidRow } from '@/lib/bssids-csv';
import { bssids as bssidsRoute } from '@/routes/device-details';

export type AccessPointDetailsPayload = {
    serial: string;
    device_name: string;
    device_type: string;
    device_function: string;
    interfaces: unknown[];
    central_error: string | null;
};

type BssidsResponse = {
    serial: string;
    bssids: BssidRow[];
    error: string | null;
    message?: string;
};

type AccessPointDetailsPanelProps = {
    accessPoint: AccessPointDetailsPayload;
};

export default function AccessPointDetailsPanel({ accessPoint }: AccessPointDetailsPanelProps) {
    const { serial, device_name, central_error } = accessPoint;
    const title = device_name !== '' ? device_name : serial;

    const [bssids, setBssids] = useState<BssidRow[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [pageIndex, setPageIndex] = useState(0);
    const pageSize = 25;

    const loadBssids = useCallback(async () => {
        setLoading(true);
        setFetchError(null);

        try {
            const response = await fetch(bssidsRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ serial }),
            });

            const body = (await response.json().catch(() => null)) as BssidsResponse | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ?? body?.message ?? `Failed to load BSSIDs (HTTP ${response.status}).`,
                );
            }

            if (body === null) {
                throw new Error('Failed to load BSSIDs: empty response.');
            }

            if (body.error) {
                throw new Error(body.error);
            }

            setBssids(body.bssids);
            setPageIndex(0);
        } catch (error) {
            setBssids(null);
            setFetchError(error instanceof Error ? error.message : 'Failed to load BSSIDs.');
        } finally {
            setLoading(false);
        }
    }, [serial]);

    const columns = useMemo<ColumnDef<BssidRow>[]>(
        () => [
            { accessorKey: 'bssid', header: 'BSSID' },
            { accessorKey: 'wlanName', header: 'WLAN' },
            {
                accessorKey: 'radioNumber',
                header: 'Radio',
                cell: ({ row }) =>
                    row.original.radioNumber === null ? '—' : String(row.original.radioNumber),
            },
            { accessorKey: 'radioMacAddress', header: 'Radio MAC' },
            { accessorKey: 'macAddress', header: 'AP MAC' },
            {
                accessorKey: 'clientCount',
                header: 'Clients',
                cell: ({ row }) =>
                    row.original.clientCount === null ? '—' : String(row.original.clientCount),
            },
            { accessorKey: 'siteName', header: 'Site' },
            { accessorKey: 'clusterId', header: 'Cluster ID' },
        ],
        [],
    );

    const rows = bssids ?? [];
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, rows.length);
    const pagedRows = useMemo(() => rows.slice(start, end), [rows, start, end]);

    return (
        <section className="mt-8 border-t border-border pt-8 first:mt-0 first:border-t-0 first:pt-0">
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold" data-test="device-details-ap-title">
                        {title}
                    </h2>
                    {device_name !== '' ? (
                        <p
                            className="mt-1 text-sm text-muted-foreground"
                            data-test="device-details-ap-serial"
                        >
                            Serial: {serial}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="gap-2"
                        disabled={loading || Boolean(central_error)}
                        onClick={() => void loadBssids()}
                        data-test="device-details-bssids"
                    >
                        {loading ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                        ) : (
                            <Wifi className="size-4" aria-hidden />
                        )}
                        BSSIDs
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="gap-2"
                        disabled={rows.length === 0}
                        onClick={() => downloadBssidsCsv(rows, serial)}
                        data-test="device-details-bssids-export-csv"
                    >
                        <Download className="size-4" aria-hidden />
                        Export CSV
                    </Button>
                </div>
            </div>

            {central_error && (
                <div
                    className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    role="alert"
                    data-test="device-details-ap-error"
                >
                    {central_error}
                </div>
            )}

            {fetchError && (
                <div
                    className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    role="alert"
                    data-test="device-details-bssids-error"
                >
                    {fetchError}
                </div>
            )}

            {bssids !== null ? (
                <>
                    <h3 className="mb-3 text-lg font-medium" data-test="device-details-bssids-heading">
                        BSSIDs
                    </h3>

                    {rows.length === 0 ? (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="device-details-bssids-empty"
                        >
                            No BSSIDs found for this access point.
                        </p>
                    ) : (
                        <>
                            <DataTable columns={columns} data={pagedRows} />
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                                <span data-test="device-details-bssids-count">
                                    Showing {start + 1}–{end} of {rows.length}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={safePageIndex <= 0}
                                        onClick={() => setPageIndex((prev) => Math.max(0, prev - 1))}
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={safePageIndex >= totalPages - 1}
                                        onClick={() =>
                                            setPageIndex((prev) => Math.min(totalPages - 1, prev + 1))
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </>
            ) : null}
        </section>
    );
}
