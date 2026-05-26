import type { ColumnDef } from '@tanstack/react-table';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { csrfHeaders } from '@/lib/csrf';
import {
    formatPayloadCellValue,
    getLagPathEditor,
    parsePayloadCellValue,
    type PathEditorConfig,
} from '@/lib/interface-payload-column-editors';

export type FailedInterfaceRow = {
    device_interface_id: number;
    device_name: string;
    interface: string;
    details: Array<{ path: string; expected: unknown; actual: unknown }>;
};

const READ_ONLY_PATHS = new Set(['id', 'name', 'is-valid']);

function resolvePathEditor(path: string, kind: 'lag' | 'vlan' | 'ethernet'): PathEditorConfig {
    if (kind === 'lag') {
        return getLagPathEditor(path);
    }

    return { type: 'text' };
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
                    valuesByPath[path] = formatPayloadCellValue(
                        path,
                        detail?.expected,
                    );
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
                    ...csrfHeaders(),
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
            const editor = resolvePathEditor(path, kind);
            base.push({
                id: path,
                header: path,
                cell: ({ row }) => (
                    <EditablePathCell
                        path={path}
                        editor={editor}
                        value={row.original.valuesByPath[path] ?? ''}
                        onCommit={(next) => {
                            if (!pendingRef.current[row.original.device_interface_id]) {
                                pendingRef.current[row.original.device_interface_id] = {};
                            }
                            pendingRef.current[row.original.device_interface_id][path] =
                                parsePayloadCellValue(path, next);
                        }}
                        onBlur={() => {
                            void flushRow(row.original.device_interface_id);
                        }}
                        onSelectCommit={() => {
                            void flushRow(row.original.device_interface_id);
                        }}
                    />
                ),
            });
        }

        return base;
    }, [flushRow, kind, payloadPaths]);

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
    editor,
    value,
    onCommit,
    onBlur,
    onSelectCommit,
}: {
    path: string;
    editor: PathEditorConfig;
    value: string;
    onCommit: (value: string) => void;
    onBlur: () => void;
    onSelectCommit: () => void;
}) {
    const [local, setLocal] = useState(value);

    useEffect(() => {
        setLocal(value);
    }, [value]);

    if (editor.type === 'enum' || editor.type === 'boolean') {
        const baseOptions = editor.options ?? [];
        const options =
            local !== '' && !baseOptions.includes(local)
                ? [...baseOptions, local]
                : baseOptions;
        const selectValue =
            local !== '' && options.includes(local) ? local : (options[0] ?? '');

        return (
            <Select
                value={selectValue}
                onValueChange={(next) => {
                    setLocal(next);
                    onCommit(next);
                    onSelectCommit();
                }}
            >
                <SelectTrigger className="h-8 min-w-[6rem] font-mono text-xs" aria-label={path}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((opt) => (
                        <SelectItem key={opt} value={opt}>
                            {opt}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    const placeholder =
        editor.type === 'port-list' ? '1/1/1-1/1/2, 1/1/3' : undefined;

    return (
        <Input
            className="min-w-[8rem] font-mono text-xs"
            value={local}
            placeholder={placeholder}
            aria-label={path}
            onChange={(e) => {
                setLocal(e.target.value);
                onCommit(e.target.value);
            }}
            onBlur={onBlur}
        />
    );
}
