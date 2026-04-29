import { router, usePage } from '@inertiajs/react';
import { type ColumnDef, type VisibilityState } from '@tanstack/react-table';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { show as showDevice } from '@/routes/devices';
import type { BreadcrumbItem, SharedData } from '@/types';

export type SwitchPortDetail = {
    access_vlan: number | null;
    interface_mode: string;
    native_vlan: number | null;
    trunk_vlan_all: boolean | null;
    trunk_vlan_ranges: string | null;
};

export type LacpProfileDetail = {
    mode: string;
    port_id: number | null;
    rate: string;
    trunk_type: string;
    port_list: string[];
};

export type StpProfileDetail = {
    admin_edge_port: boolean;
    admin_edge_port_trunk: boolean;
    bpdu_guard: boolean;
    loop_guard: boolean;
};

export type DeviceInterfaceRow = {
    id: number;
    interface: string;
    description: string | null;
    ip_address: string | null;
    enable: boolean;
    jumbo_frames: boolean;
    routing: boolean;
    vrf_forwarding: string;
    sw_profile: string | null;
    portchannel_lag: string | null;
    switch_port: SwitchPortDetail | null;
    lacp_profile: LacpProfileDetail | null;
    stp_profile: StpProfileDetail | null;
};

type InterfaceDraftRow = {
    description?: string | null;
    ip_address?: string | null;
    enable?: boolean;
    jumbo_frames?: boolean;
    routing?: boolean;
    vrf_forwarding?: string | null;
    sw_profile?: string | null;
    portchannel_lag?: string | null;
    interface_mode?: string | null;
    access_vlan?: number | null;
    native_vlan?: number | null;
    trunk_vlan_all?: boolean;
    trunk_vlan_ranges?: string | null;
    lacp_mode?: string | null;
    lacp_port_id?: number | null;
    lacp_rate?: string | null;
    trunk_type?: string | null;
    lacp_port_list?: string;
    admin_edge_port?: boolean;
    admin_edge_port_trunk?: boolean;
    bpdu_guard?: boolean;
    loop_guard?: boolean;
};

function yesNo(value: boolean): string {
    return value ? 'Yes' : 'No';
}

function formatDeviceMetadata(device: {
    site: string | null;
    group: string | null;
    serial: string | null;
    scope_id: string | null;
    device_function: string | null;
}): string | null {
    const parts: string[] = [];
    const pushLabeled = (label: string, value: string | null | undefined) => {
        if (value == null) {
            return;
        }
        const trimmed = String(value).trim();
        if (trimmed === '') {
            return;
        }
        parts.push(`${label} ${trimmed}`);
    };
    pushLabeled('Site', device.site);
    pushLabeled('Group', device.group);
    pushLabeled('Serial', device.serial);
    pushLabeled('Scope ID', device.scope_id);
    if (device.device_function != null) {
        const fn = String(device.device_function).trim();
        if (fn !== '') {
            parts.push(fn);
        }
    }
    return parts.length > 0 ? parts.join(' · ') : null;
}

function hasIpAddress(row: DeviceInterfaceRow): boolean {
    return Boolean(row.ip_address?.trim());
}

function formatInterfaceDisplay(row: DeviceInterfaceRow): string {
    const tags: string[] = [];
    if (hasIpAddress(row)) {
        tags.push('VLAN');
    }
    if (row.lacp_profile) {
        tags.push('LAG');
    }
    const prefix = tags.length > 0 ? `${tags.join(' ')} ` : '';
    return `${prefix}${row.interface}`;
}

function displaySwitchPortCell(
    row: DeviceInterfaceRow,
    pick: (sp: SwitchPortDetail) => string | number | boolean | null,
): string {
    if (hasIpAddress(row) || row.switch_port == null) {
        return '—';
    }
    const v = pick(row.switch_port);
    if (v === null || v === '') {
        return '—';
    }
    if (typeof v === 'boolean') {
        return yesNo(v);
    }
    return String(v);
}

function displayLacpCell(
    row: DeviceInterfaceRow,
    pick: (lp: LacpProfileDetail) => string | number | string[] | null,
): string {
    if (row.lacp_profile == null) {
        return '—';
    }
    const v = pick(row.lacp_profile);
    if (v === null) {
        return '—';
    }
    if (Array.isArray(v)) {
        return v.length > 0 ? v.map(String).join(', ') : '—';
    }
    if (typeof v === 'string' && v.trim() === '') {
        return '—';
    }
    return String(v);
}

function displayStpCell(row: DeviceInterfaceRow, key: keyof StpProfileDetail): string {
    if (row.stp_profile == null) {
        return '—';
    }
    return yesNo(Boolean(row.stp_profile[key]));
}

function createInterfaceColumns(
    editing: boolean,
    getDraftValue: <T>(row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: T) => T,
    onDraftChange: (id: number, key: keyof InterfaceDraftRow, value: unknown) => void,
): ColumnDef<DeviceInterfaceRow>[] {
    const textCell = (row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: string | null) => {
        if (!editing) {
            return fallback ?? '—';
        }
        return (
            <input
                className="w-full rounded border px-2 py-1 text-sm"
                value={String(getDraftValue(row, key, fallback ?? ''))}
                onChange={(e) => onDraftChange(row.id, key, e.target.value)}
            />
        );
    };

    const boolCell = (row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: boolean) => {
        const value = Boolean(getDraftValue(row, key, fallback));
        if (!editing) {
            return yesNo(value);
        }
        return (
            <input
                type="checkbox"
                checked={value}
                onChange={(e) => onDraftChange(row.id, key, e.target.checked)}
            />
        );
    };

    return [
        { accessorKey: 'id', header: 'ID' },
        {
            id: 'interface',
            header: 'Interface',
            enableHiding: false,
            accessorFn: (row) => formatInterfaceDisplay(row),
        },
        { accessorKey: 'description', header: 'Description', cell: ({ row }) => textCell(row.original, 'description', row.original.description) },
        { accessorKey: 'ip_address', header: 'IP address', cell: ({ row }) => textCell(row.original, 'ip_address', row.original.ip_address) },
        { accessorKey: 'enable', header: 'Enabled', cell: ({ row }) => boolCell(row.original, 'enable', Boolean(row.original.enable)) },
        { accessorKey: 'jumbo_frames', header: 'Jumbo frames', cell: ({ row }) => boolCell(row.original, 'jumbo_frames', Boolean(row.original.jumbo_frames)) },
        { accessorKey: 'routing', header: 'Routing', cell: ({ row }) => boolCell(row.original, 'routing', Boolean(row.original.routing)) },
        { accessorKey: 'vrf_forwarding', header: 'VRF forwarding', cell: ({ row }) => textCell(row.original, 'vrf_forwarding', row.original.vrf_forwarding) },
        { accessorKey: 'sw_profile', header: 'Port profile', cell: ({ row }) => textCell(row.original, 'sw_profile', row.original.sw_profile) },
        { accessorKey: 'portchannel_lag', header: 'Port-channel / LAG', cell: ({ row }) => textCell(row.original, 'portchannel_lag', row.original.portchannel_lag) },
        {
            id: 'switch_port_mode',
            header: 'Port mode',
            cell: ({ row }) => textCell(row.original, 'interface_mode', row.original.switch_port?.interface_mode ?? null),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.interface_mode)),
        },
        {
            id: 'switch_port_access_vlan',
            header: 'Access VLAN',
            cell: ({ row }) => textCell(row.original, 'access_vlan', row.original.switch_port?.access_vlan?.toString() ?? null),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.access_vlan)),
        },
        {
            id: 'switch_port_native_vlan',
            header: 'Native VLAN',
            cell: ({ row }) => textCell(row.original, 'native_vlan', row.original.switch_port?.native_vlan?.toString() ?? null),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.native_vlan)),
        },
        {
            id: 'switch_port_trunk_vlan_all',
            header: 'Trunk all VLANs',
            cell: ({ row }) => boolCell(row.original, 'trunk_vlan_all', Boolean(row.original.switch_port?.trunk_vlan_all ?? false)),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.trunk_vlan_all)),
        },
        {
            id: 'switch_port_trunk_vlan_ranges',
            header: 'Trunk VLAN ranges',
            cell: ({ row }) => textCell(row.original, 'trunk_vlan_ranges', row.original.switch_port?.trunk_vlan_ranges ?? null),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.trunk_vlan_ranges)),
        },
        {
            id: 'lacp_mode',
            header: 'LACP mode',
            cell: ({ row }) => textCell(row.original, 'lacp_mode', row.original.lacp_profile?.mode ?? null),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.mode)),
        },
        {
            id: 'lacp_port_id',
            header: 'LACP port ID',
            cell: ({ row }) => textCell(row.original, 'lacp_port_id', row.original.lacp_profile?.port_id?.toString() ?? null),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.port_id)),
        },
        {
            id: 'lacp_rate',
            header: 'LACP rate',
            cell: ({ row }) => textCell(row.original, 'lacp_rate', row.original.lacp_profile?.rate ?? null),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.rate)),
        },
        {
            id: 'lacp_trunk_type',
            header: 'LACP trunk type',
            cell: ({ row }) => textCell(row.original, 'trunk_type', row.original.lacp_profile?.trunk_type ?? null),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.trunk_type)),
        },
        {
            id: 'lacp_port_list',
            header: 'LACP port list',
            cell: ({ row }) => textCell(row.original, 'lacp_port_list', row.original.lacp_profile?.port_list.join(', ') ?? null),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.port_list)),
        },
        {
            id: 'stp_admin_edge',
            header: 'STP admin edge',
            cell: ({ row }) => boolCell(row.original, 'admin_edge_port', Boolean(row.original.stp_profile?.admin_edge_port ?? false)),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'admin_edge_port')),
        },
        {
            id: 'stp_admin_edge_trunk',
            header: 'STP admin edge trunk',
            cell: ({ row }) => boolCell(row.original, 'admin_edge_port_trunk', Boolean(row.original.stp_profile?.admin_edge_port_trunk ?? false)),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'admin_edge_port_trunk')),
        },
        {
            id: 'stp_bpdu_guard',
            header: 'STP BPDU guard',
            cell: ({ row }) => boolCell(row.original, 'bpdu_guard', Boolean(row.original.stp_profile?.bpdu_guard ?? false)),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'bpdu_guard')),
        },
        {
            id: 'stp_loop_guard',
            header: 'STP loop guard',
            cell: ({ row }) => boolCell(row.original, 'loop_guard', Boolean(row.original.stp_profile?.loop_guard ?? false)),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'loop_guard')),
        },
    ];
}

const DEVICE_SHOW_VISIBILITY_KEY = 'device-show-columns:v1';

const SIMPLE_VISIBLE_COLUMN_IDS = new Set<string>([
    'interface',
    'description',
    'ip_address',
    'enable',
    'vrf_forwarding',
    'sw_profile',
    'portchannel_lag',
]);

const PROFILE_COLUMN_IDS = new Set<string>([
    'switch_port_mode',
    'switch_port_access_vlan',
    'switch_port_native_vlan',
    'switch_port_trunk_vlan_all',
    'switch_port_trunk_vlan_ranges',
    'lacp_mode',
    'lacp_port_id',
    'lacp_rate',
    'lacp_trunk_type',
    'lacp_port_list',
    'stp_admin_edge',
    'stp_admin_edge_trunk',
    'stp_bpdu_guard',
    'stp_loop_guard',
]);

function buildVisibilityPreset(
    allColumnIds: string[],
    visible: Set<string>,
): VisibilityState {
    return Object.fromEntries(
        allColumnIds.map((id) => [id, visible.has(id)]),
    );
}

function isValidVisibilityState(
    value: unknown,
    allowedColumnIds: Set<string>,
): value is VisibilityState {
    if (typeof value !== 'object' || value == null || Array.isArray(value)) {
        return false;
    }
    return Object.keys(value).every((key) => {
        const cell = (value as Record<string, unknown>)[key];
        return allowedColumnIds.has(key) && typeof cell === 'boolean';
    });
}

type DeviceShowProps = {
    device: {
        id: number;
        name: string;
        site: string | null;
        group: string | null;
        serial: string | null;
        scope_id: string | null;
        device_function: string | null;
    };
    deployment: {
        id: number;
        name: string;
    };
    interfaces: DeviceInterfaceRow[];
} & SharedData;

export default function Show() {
    const { device, deployment, current_client, interfaces } =
        usePage<DeviceShowProps>().props;
    const [isEditing, setIsEditing] = useState(false);
    const [drafts, setDrafts] = useState<Record<number, InterfaceDraftRow>>({});
    const [isSaving, setIsSaving] = useState(false);

    const onDraftChange = (id: number, key: keyof InterfaceDraftRow, value: unknown) => {
        setDrafts((current) => ({
            ...current,
            [id]: {
                ...current[id],
                [key]: value,
            },
        }));
    };

    const getDraftValue = <T,>(row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: T): T => {
        const rowDraft = drafts[row.id];
        if (!rowDraft || !(key in rowDraft)) {
            return fallback;
        }
        return rowDraft[key] as T;
    };

    const interfaceColumns = useMemo(
        () => createInterfaceColumns(isEditing, getDraftValue, onDraftChange),
        [isEditing, drafts],
    );

    const allLeafColumnIds = useMemo(() => {
        return interfaceColumns.map((column) => {
            if ('id' in column && typeof column.id === 'string') {
                return column.id;
            }
            if (
                'accessorKey' in column &&
                (typeof column.accessorKey === 'string' ||
                    typeof column.accessorKey === 'number')
            ) {
                return String(column.accessorKey);
            }
            return '';
        }).filter((id) => id.length > 0);
    }, [interfaceColumns]);

    const allColumnIdSet = useMemo(() => new Set(allLeafColumnIds), [allLeafColumnIds]);

    const simplePreset = useMemo(
        () => buildVisibilityPreset(allLeafColumnIds, SIMPLE_VISIBLE_COLUMN_IDS),
        [allLeafColumnIds],
    );
    const profilesPreset = useMemo(
        () =>
            buildVisibilityPreset(
                allLeafColumnIds,
                new Set([...SIMPLE_VISIBLE_COLUMN_IDS, ...PROFILE_COLUMN_IDS]),
            ),
        [allLeafColumnIds],
    );
    const fullPreset = useMemo(
        () => buildVisibilityPreset(allLeafColumnIds, new Set(allLeafColumnIds)),
        [allLeafColumnIds],
    );

    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() => {
        if (typeof window === 'undefined') {
            return simplePreset;
        }
        const raw = window.localStorage.getItem(DEVICE_SHOW_VISIBILITY_KEY);
        if (!raw) {
            return simplePreset;
        }
        try {
            const parsed: unknown = JSON.parse(raw);
            if (isValidVisibilityState(parsed, allColumnIdSet)) {
                return parsed;
            }
        } catch {
            // Fall through to default preset.
        }
        return simplePreset;
    });

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }
        window.localStorage.setItem(
            DEVICE_SHOW_VISIBILITY_KEY,
            JSON.stringify(columnVisibility),
        );
    }, [columnVisibility]);

    const deviceSubtitle = formatDeviceMetadata(device);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: deployment.name,
            href: showDeployment(deployment.id).url,
        },
        {
            title: device.name,
            href: showDevice(device.id).url,
        },
    ];

    const pendingUpdates = useMemo(() => {
        return Object.entries(drafts).map(([id, rowDraft]) => {
            const update = { id: Number(id), ...rowDraft } as Record<string, unknown>;
            if (typeof update.access_vlan === 'string') {
                update.access_vlan = update.access_vlan === '' ? null : Number(update.access_vlan);
            }
            if (typeof update.native_vlan === 'string') {
                update.native_vlan = update.native_vlan === '' ? null : Number(update.native_vlan);
            }
            if (typeof update.lacp_port_id === 'string') {
                update.lacp_port_id = update.lacp_port_id === '' ? null : Number(update.lacp_port_id);
            }
            if (typeof update.lacp_port_list === 'string') {
                update.lacp_port_list = update.lacp_port_list
                    .split(',')
                    .map((p) => p.trim())
                    .filter((p) => p.length > 0);
            }
            return update;
        });
    }, [drafts]);

    const saveDrafts = () => {
        setIsSaving(true);
        router.patch(`/devices/${device.id}/interfaces`, { updates: pendingUpdates as any }, {
            preserveScroll: true,
            onFinish: () => setIsSaving(false),
            onSuccess: () => {
                setDrafts({});
                setIsEditing(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{device.name}</h1>
                    {deviceSubtitle ? (
                        <p className="text-muted-foreground text-sm">
                            {deviceSubtitle}
                        </p>
                    ) : null}
                </div>
                <div>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-medium">Interfaces</h2>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setIsEditing((v) => !v)}
                            >
                                {isEditing ? 'View' : 'Edit'}
                            </Button>
                            {isEditing ? (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setDrafts({})}
                                    >
                                        Discard Changes
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={pendingUpdates.length === 0 || isSaving}
                                        onClick={saveDrafts}
                                    >
                                        Save Changes
                                    </Button>
                                </>
                            ) : null}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setColumnVisibility(simplePreset)}
                            >
                                Simple
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setColumnVisibility(profilesPreset)}
                            >
                                Profiles
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setColumnVisibility(fullPreset)}
                            >
                                Full
                            </Button>
                        </div>
                    </div>
                    <DataTable<DeviceInterfaceRow, unknown>
                        data={interfaces}
                        columns={interfaceColumns}
                        getRowId={(row) => String(row.id)}
                        stickyLeftColumnIds={['interface']}
                        enableColumnPicker
                        columnPickerTitle="Columns"
                        columnVisibility={columnVisibility}
                        onColumnVisibilityChange={setColumnVisibility}
                        columnGroups={[
                            {
                                label: 'Core',
                                columnIds: [
                                    'id',
                                    'interface',
                                    'description',
                                    'ip_address',
                                    'enable',
                                    'jumbo_frames',
                                    'routing',
                                    'vrf_forwarding',
                                    'sw_profile',
                                    'portchannel_lag',
                                ],
                            },
                            {
                                label: 'Switch Port',
                                columnIds: [
                                    'switch_port_mode',
                                    'switch_port_access_vlan',
                                    'switch_port_native_vlan',
                                    'switch_port_trunk_vlan_all',
                                    'switch_port_trunk_vlan_ranges',
                                ],
                            },
                            {
                                label: 'LACP',
                                columnIds: [
                                    'lacp_mode',
                                    'lacp_port_id',
                                    'lacp_rate',
                                    'lacp_trunk_type',
                                    'lacp_port_list',
                                ],
                            },
                            {
                                label: 'STP',
                                columnIds: [
                                    'stp_admin_edge',
                                    'stp_admin_edge_trunk',
                                    'stp_bpdu_guard',
                                    'stp_loop_guard',
                                ],
                            },
                        ]}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
