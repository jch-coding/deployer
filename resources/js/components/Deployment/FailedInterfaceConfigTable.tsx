import type { ColumnDef } from '@tanstack/react-table';
import { useCallback, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';

export type FailedInterfaceRow = {
    device_interface_id: number;
    device_name: string;
    interface: string;
    details: Array<{ path: string; expected: unknown; actual: unknown }>;
};

const READ_ONLY_PATHS = new Set(['id', 'name', 'is-valid']);

function formatCellValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function parseCellValue(path: string, raw: string): unknown {
    if (path === 'enable' || path.startsWith('stp.') || path === 'switchport.trunk-vlan-all' || path === 'vsx.shutdown-on-split') {
        if (raw === 'true') {
            return true;
        }
        if (raw === 'false') {
            return false;
        }
    }
    if (path === 'port-list' && raw.includes(',')) {
        return raw.split(',').map((p) => p.trim()).filter(Boolean);
    }

    return raw;
}

type TableRow = {
    id: string;
    device_interface_id: number;
    device_name: string;
    interface: string;
    valuesByPath: Record<string, string>;
};

type FailedInterfaceConfigTableProps = {
    title: string;
    rows: FailedInterfaceRow[];
    kind: 'lag' | 'vlan' | 'ethernet';
    patchUrl: string;
    emptyMessage?: string;
};

export default function FailedInterfaceConfigTable({
    title,
    rows,
    kind,
    patchUrl,
    emptyMessage = 'No failures in this category.',
}: FailedInterfaceConfigTableProps) {
    const pendingRef = useRef<Record<number, Record<string, unknown>>>({});

    const payloadPaths = useMemo(() => {
        const paths = new Set<string>();
        for (const row of rows) {
            for (const detail of row.details) {
                if (!READ_ONLY_PATHS.has(detail.path)) {
                    paths.add(detail.path);
                }
            }
        }

        return Array.from(paths).sort();
    }, [rows]);

    const tableData = useMemo<TableRow[]>(
        () =>
            rows.map((row) => {
                const valuesByPath: Record<string, string> = {};
                for (const path of payloadPaths) {
                    const detail = row.details.find((d) => d.path === path);
                    valuesByPath[path] = formatCellValue(detail?.expected);
                }

                return {
                    id: String(row.device_interface_id),
                    device_interface_id: row.device_interface_id,
                    device_name: row.device_name,
                    interface: row.interface,
                    valuesByPath,
                };
            }),
        [rows, payloadPaths],
    );

    const saveRow = useCallback(
        async (deviceInterfaceId: number, attributes: Record<string, unknown>) => {
            const response = await fetch(patchUrl, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    updates: [
                        {
                            device_interface_id: deviceInterfaceId,
                            kind,
                            attributes,
                        },
                    ],
                }),
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as { message?: string } | null;
                throw new Error(body?.message ?? `Save failed (HTTP ${response.status}).`);
            }
        },
        [kind, patchUrl],
    );

    const flushRow = useCallback(
        async (deviceInterfaceId: number) => {
            const attributes = pendingRef.current[deviceInterfaceId];
            if (!attributes || Object.keys(attributes).length === 0) {
                return;
            }
            await saveRow(deviceInterfaceId, attributes);
            delete pendingRef.current[deviceInterfaceId];
        },
        [saveRow],
    );

    const columns = useMemo<ColumnDef<TableRow>[]>(() => {
        const base: ColumnDef<TableRow>[] = [
            {
                id: 'device_name',
                accessorKey: 'device_name',
                header: 'Device',
            },
            {
                id: 'interface',
                accessorKey: 'interface',
                header: 'Interface',
            },
        ];

        for (const path of payloadPaths) {
            base.push({
                id: path,
                header: path,
                cell: ({ row }) => (
                    <EditablePathCell
                        path={path}
                        value={row.original.valuesByPath[path] ?? ''}
                        onCommit={(next) => {
                            if (!pendingRef.current[row.original.device_interface_id]) {
                                pendingRef.current[row.original.device_interface_id] = {};
                            }
                            pendingRef.current[row.original.device_interface_id][path] = parseCellValue(
                                path,
                                next,
                            );
                        }}
                        onBlur={() => {
                            void flushRow(row.original.device_interface_id);
                        }}
                    />
                ),
            });
        }

        return base;
    }, [flushRow, payloadPaths]);

    if (rows.length === 0) {
        return (
            <div className="space-y-2">
                <h3 className="text-base font-semibold">{title}</h3>
                <p className="text-muted-foreground text-sm">{emptyMessage}</p>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <h3 className="text-base font-semibold">{title}</h3>
            <div className="overflow-x-auto rounded-md border">
                <DataTable columns={columns} data={tableData} getRowId={(row) => row.id} />
            </div>
        </div>
    );
}

function EditablePathCell({
    path,
    value,
    onCommit,
    onBlur,
}: {
    path: string;
    value: string;
    onCommit: (value: string) => void;
    onBlur: () => void;
}) {
    const [local, setLocal] = useState(value);

    if (local !== value && document.activeElement?.tagName !== 'INPUT') {
        setLocal(value);
    }

    return (
        <Input
            className="min-w-[8rem] font-mono text-xs"
            value={local}
            aria-label={path}
            onChange={(e) => {
                setLocal(e.target.value);
                onCommit(e.target.value);
            }}
            onBlur={onBlur}
        />
    );
}
