import { router, usePage } from '@inertiajs/react';
import { type ColumnDef, type VisibilityState } from '@tanstack/react-table';
import {
    type ComponentProps,
    type ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
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

const INTERFACE_MODES = ['ACCESS', 'TRUNK'] as const;
const LACP_MODES = ['ACTIVE', 'PASSIVE', 'AUTO'] as const;
const LACP_RATES = ['FAST', 'SLOW'] as const;
const LACP_TRUNK_TYPES = [
    'LACP',
    'TRUNK',
    'DT_TRUNK',
    'MULTI_CHASSIS',
    'MULTI_CHASSIS_STATIC',
] as const;

function mapValidationErrorsToRows(
    errors: Record<string, string> | undefined,
    orderedInterfaceIds: number[],
): Map<number, Record<string, string>> {
    const map = new Map<number, Record<string, string>>();
    if (!errors) {
        return map;
    }
    for (const [key, message] of Object.entries(errors)) {
        const match = /^updates\.(\d+)\.(.+)$/.exec(key);
        if (!match) {
            continue;
        }
        const idx = Number(match[1]);
        const field = match[2];
        const id = orderedInterfaceIds[idx];
        if (id === undefined) {
            continue;
        }
        const row = map.get(id) ?? {};
        row[field] = message;
        map.set(id, row);
    }
    return map;
}

function dismissedCellErrorKey(interfaceId: number, field: string): string {
    return `${interfaceId}:${field}`;
}

function filterFieldErrorsByDismissed(
    map: Map<number, Record<string, string>>,
    dismissed: ReadonlySet<string>,
): Map<number, Record<string, string>> {
    if (dismissed.size === 0) {
        return map;
    }
    const out = new Map<number, Record<string, string>>();
    for (const [id, row] of map) {
        const filtered: Record<string, string> = {};
        for (const [field, message] of Object.entries(row)) {
            if (!dismissed.has(dismissedCellErrorKey(id, field))) {
                filtered[field] = message;
            }
        }
        if (Object.keys(filtered).length > 0) {
            out.set(id, filtered);
        }
    }
    return out;
}

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

type InterfaceColumnOptions = {
    editing: boolean;
    getDraftValue: <T>(row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: T) => T;
    setDraftField: (id: number, key: keyof InterfaceDraftRow, value: unknown) => void;
    clearDraftField: (id: number, key: keyof InterfaceDraftRow) => void;
    getFieldError: (interfaceId: number, field: string) => string | undefined;
    allInterfaceIds: number[];
    selectedInterfaceIds: ReadonlySet<number>;
    toggleSelect: (id: number) => void;
    toggleSelectAll: () => void;
    allRowsSelected: boolean;
    someRowsSelected: boolean;
};

function FieldWrap({
    children,
    error,
    className,
}: {
    children: ReactNode;
    error?: string;
    className?: string;
}) {
    return (
        <div className={cn('min-w-[6.5rem] space-y-1', className)}>
            {children}
            {error ? (
                <p className="text-destructive text-xs leading-tight" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function createInterfaceColumns({
    editing,
    getDraftValue,
    setDraftField,
    clearDraftField,
    getFieldError,
    allInterfaceIds,
    selectedInterfaceIds,
    toggleSelect,
    toggleSelectAll,
    allRowsSelected,
    someRowsSelected,
}: InterfaceColumnOptions): ColumnDef<DeviceInterfaceRow>[] {
    const fieldErr = (interfaceId: number, field: string) => getFieldError(interfaceId, field);
    const textCell = (
        row: DeviceInterfaceRow,
        key: keyof InterfaceDraftRow,
        fallback: string | null,
        field: string,
        inputProps?: ComponentProps<typeof Input>,
    ) => {
        if (!editing) {
            return fallback ?? '—';
        }
        const err = fieldErr(row.id, field);
        return (
            <FieldWrap error={err}>
                <Input
                    {...inputProps}
                    className="h-8 text-sm"
                    aria-invalid={Boolean(err)}
                    value={String(getDraftValue(row, key, fallback ?? ''))}
                    onChange={(e) => setDraftField(row.id, key, e.target.value)}
                />
            </FieldWrap>
        );
    };

    const boolCell = (
        row: DeviceInterfaceRow,
        key: keyof InterfaceDraftRow,
        fallback: boolean,
        field: string,
    ) => {
        const value = Boolean(getDraftValue(row, key, fallback));
        if (!editing) {
            return yesNo(value);
        }
        const err = fieldErr(row.id, field);
        return (
            <FieldWrap error={err} className="flex min-h-8 items-center">
                <Checkbox
                    checked={value}
                    aria-invalid={Boolean(err)}
                    onCheckedChange={(checked) =>
                        setDraftField(row.id, key, checked === true)}
                />
            </FieldWrap>
        );
    };

    const enumSelect = (
        row: DeviceInterfaceRow,
        key: keyof InterfaceDraftRow,
        fallback: string | null,
        options: readonly string[],
        field: string,
        placeholder: string,
    ) => {
        if (!editing) {
            switch (key) {
                case 'interface_mode':
                    return displaySwitchPortCell(row, (sp) => sp.interface_mode);
                case 'lacp_mode':
                    return displayLacpCell(row, (lp) => lp.mode);
                case 'lacp_rate':
                    return displayLacpCell(row, (lp) => lp.rate);
                case 'trunk_type':
                    return displayLacpCell(row, (lp) => lp.trunk_type);
                default:
                    return '—';
            }
        }
        const raw = getDraftValue(row, key, fallback ?? '');
        const str = raw === null || raw === undefined ? '' : String(raw);
        const selectValue = str === '' ? '__none__' : str;
        const err = fieldErr(row.id, field);
        return (
            <FieldWrap error={err}>
                <Select
                    value={selectValue}
                    onValueChange={(v) => {
                        if (v === '__none__') {
                            clearDraftField(row.id, key);
                        } else {
                            setDraftField(row.id, key, v);
                        }
                    }}
                >
                    <SelectTrigger
                        className="h-8 w-full min-w-[6rem] text-xs"
                        aria-invalid={Boolean(err)}
                    >
                        <SelectValue placeholder={placeholder} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__" className="text-muted-foreground">
                            Unchanged
                        </SelectItem>
                        {options.map((opt) => (
                            <SelectItem key={opt} value={opt}>
                                {opt}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FieldWrap>
        );
    };

    const selectColumn: ColumnDef<DeviceInterfaceRow> | null = editing
        ? {
              id: 'select',
              enableHiding: false,
              header: () =>
                  allInterfaceIds.length > 0 ? (
                      <div className="flex justify-center px-1">
                          <Checkbox
                              checked={
                                  allRowsSelected
                                      ? true
                                      : someRowsSelected
                                        ? 'indeterminate'
                                        : false
                              }
                              aria-label="Select all interfaces"
                              onCheckedChange={() => toggleSelectAll()}
                          />
                      </div>
                  ) : null,
              cell: ({ row }) => (
                  <div className="flex justify-center px-1">
                      <Checkbox
                          checked={selectedInterfaceIds.has(row.original.id)}
                          aria-label={`Select ${row.original.interface}`}
                          onCheckedChange={() => toggleSelect(row.original.id)}
                      />
                  </div>
              ),
              accessorFn: () => '',
          }
        : null;

    return [
        ...(selectColumn ? [selectColumn] : []),
        { accessorKey: 'id', header: 'ID' },
        {
            id: 'interface',
            header: 'Interface',
            enableHiding: false,
            accessorFn: (row) => formatInterfaceDisplay(row),
        },
        {
            accessorKey: 'description',
            header: 'Description',
            cell: ({ row }) =>
                textCell(row.original, 'description', row.original.description, 'description'),
        },
        {
            accessorKey: 'ip_address',
            header: 'IP address',
            cell: ({ row }) =>
                textCell(row.original, 'ip_address', row.original.ip_address, 'ip_address'),
        },
        {
            accessorKey: 'enable',
            header: 'Enabled',
            cell: ({ row }) => boolCell(row.original, 'enable', Boolean(row.original.enable), 'enable'),
        },
        {
            accessorKey: 'jumbo_frames',
            header: 'Jumbo frames',
            cell: ({ row }) =>
                boolCell(row.original, 'jumbo_frames', Boolean(row.original.jumbo_frames), 'jumbo_frames'),
        },
        {
            accessorKey: 'routing',
            header: 'Routing',
            cell: ({ row }) => boolCell(row.original, 'routing', Boolean(row.original.routing), 'routing'),
        },
        {
            accessorKey: 'vrf_forwarding',
            header: 'VRF forwarding',
            cell: ({ row }) =>
                textCell(row.original, 'vrf_forwarding', row.original.vrf_forwarding, 'vrf_forwarding'),
        },
        {
            accessorKey: 'sw_profile',
            header: 'Port profile',
            cell: ({ row }) => textCell(row.original, 'sw_profile', row.original.sw_profile, 'sw_profile'),
        },
        {
            id: 'switch_port_mode',
            header: 'Port mode',
            cell: ({ row }) =>
                enumSelect(
                    row.original,
                    'interface_mode',
                    row.original.switch_port?.interface_mode ?? null,
                    INTERFACE_MODES,
                    'interface_mode',
                    'Mode',
                ),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.interface_mode)),
        },
        {
            id: 'switch_port_access_vlan',
            header: 'Access VLAN',
            cell: ({ row }) =>
                textCell(
                    row.original,
                    'access_vlan',
                    row.original.switch_port?.access_vlan?.toString() ?? null,
                    'access_vlan',
                    { type: 'number', min: 1, max: 4094 },
                ),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.access_vlan)),
        },
        {
            id: 'switch_port_native_vlan',
            header: 'Native VLAN',
            cell: ({ row }) =>
                textCell(
                    row.original,
                    'native_vlan',
                    row.original.switch_port?.native_vlan?.toString() ?? null,
                    'native_vlan',
                    { type: 'number', min: 1, max: 4094 },
                ),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.native_vlan)),
        },
        {
            id: 'switch_port_trunk_vlan_all',
            header: 'Trunk all VLANs',
            cell: ({ row }) =>
                boolCell(
                    row.original,
                    'trunk_vlan_all',
                    Boolean(row.original.switch_port?.trunk_vlan_all ?? false),
                    'trunk_vlan_all',
                ),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.trunk_vlan_all)),
        },
        {
            id: 'switch_port_trunk_vlan_ranges',
            header: 'Trunk VLAN ranges',
            cell: ({ row }) =>
                textCell(
                    row.original,
                    'trunk_vlan_ranges',
                    row.original.switch_port?.trunk_vlan_ranges ?? null,
                    'trunk_vlan_ranges',
                ),
            accessorFn: (row) => (editing ? '' : displaySwitchPortCell(row, (sp) => sp.trunk_vlan_ranges)),
        },
        {
            id: 'lacp_mode',
            header: 'LACP mode',
            cell: ({ row }) =>
                enumSelect(
                    row.original,
                    'lacp_mode',
                    row.original.lacp_profile?.mode ?? null,
                    LACP_MODES,
                    'lacp_mode',
                    'Mode',
                ),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.mode)),
        },
        {
            id: 'lacp_port_id',
            header: 'LACP port ID',
            cell: ({ row }) =>
                textCell(
                    row.original,
                    'lacp_port_id',
                    row.original.lacp_profile?.port_id?.toString() ?? null,
                    'lacp_port_id',
                    { type: 'number', min: 1 },
                ),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.port_id)),
        },
        {
            id: 'lacp_rate',
            header: 'LACP rate',
            cell: ({ row }) =>
                enumSelect(
                    row.original,
                    'lacp_rate',
                    row.original.lacp_profile?.rate ?? null,
                    LACP_RATES,
                    'lacp_rate',
                    'Rate',
                ),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.rate)),
        },
        {
            id: 'lacp_trunk_type',
            header: 'LACP trunk type',
            cell: ({ row }) =>
                enumSelect(
                    row.original,
                    'trunk_type',
                    row.original.lacp_profile?.trunk_type ?? null,
                    LACP_TRUNK_TYPES,
                    'trunk_type',
                    'Trunk type',
                ),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.trunk_type)),
        },
        {
            id: 'lacp_port_list',
            header: 'LACP port list',
            cell: ({ row }) =>
                textCell(
                    row.original,
                    'lacp_port_list',
                    row.original.lacp_profile?.port_list.join(', ') ?? null,
                    'lacp_port_list',
                    { placeholder: 'e.g. 1/1/1, 1/1/2' },
                ),
            accessorFn: (row) => (editing ? '' : displayLacpCell(row, (lp) => lp.port_list)),
        },
        {
            id: 'stp_admin_edge',
            header: 'STP admin edge',
            cell: ({ row }) =>
                boolCell(
                    row.original,
                    'admin_edge_port',
                    Boolean(row.original.stp_profile?.admin_edge_port ?? false),
                    'admin_edge_port',
                ),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'admin_edge_port')),
        },
        {
            id: 'stp_admin_edge_trunk',
            header: 'STP admin edge trunk',
            cell: ({ row }) =>
                boolCell(
                    row.original,
                    'admin_edge_port_trunk',
                    Boolean(row.original.stp_profile?.admin_edge_port_trunk ?? false),
                    'admin_edge_port_trunk',
                ),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'admin_edge_port_trunk')),
        },
        {
            id: 'stp_bpdu_guard',
            header: 'STP BPDU guard',
            cell: ({ row }) =>
                boolCell(
                    row.original,
                    'bpdu_guard',
                    Boolean(row.original.stp_profile?.bpdu_guard ?? false),
                    'bpdu_guard',
                ),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'bpdu_guard')),
        },
        {
            id: 'stp_loop_guard',
            header: 'STP loop guard',
            cell: ({ row }) =>
                boolCell(
                    row.original,
                    'loop_guard',
                    Boolean(row.original.stp_profile?.loop_guard ?? false),
                    'loop_guard',
                ),
            accessorFn: (row) => (editing ? '' : displayStpCell(row, 'loop_guard')),
        },
    ];
}

const DEVICE_SHOW_VISIBILITY_KEY = 'device-show-columns:v2';

/** Shown only if the user turns the column on in the column picker. */
const INTERFACE_TABLE_HIDDEN_BY_DEFAULT_IDS = new Set<string>(['id']);

const SIMPLE_VISIBLE_COLUMN_IDS = new Set<string>([
    'select',
    'interface',
    'description',
    'ip_address',
    'enable',
    'vrf_forwarding',
    'sw_profile',
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
    errors?: Record<string, string>;
} & SharedData;

const BULK_BOOL_NOOP = '__noop__' as const;
const BULK_ENUM_NOOP = '__noop__' as const;

export default function Show() {
    const { device, deployment, current_client, interfaces, errors } =
        usePage<DeviceShowProps>().props;
    const [isEditing, setIsEditing] = useState(false);
    const [drafts, setDrafts] = useState<Record<number, InterfaceDraftRow>>({});
    const [selectedInterfaceIds, setSelectedInterfaceIds] = useState<Set<number>>(() => new Set());
    const [isSaving, setIsSaving] = useState(false);
    const [dismissedFieldErrors, setDismissedFieldErrors] = useState(() => new Set<string>());
    const [bulkDescriptionText, setBulkDescriptionText] = useState('');
    const [bulkIpAddressText, setBulkIpAddressText] = useState('');
    const [bulkVrfForwardingText, setBulkVrfForwardingText] = useState('');
    const [bulkSwProfileText, setBulkSwProfileText] = useState('');
    const [bulkPortchannelLagText, setBulkPortchannelLagText] = useState('');
    const [bulkAccessVlanText, setBulkAccessVlanText] = useState('');
    const [bulkNativeVlanText, setBulkNativeVlanText] = useState('');
    const [bulkTrunkVlanRangesText, setBulkTrunkVlanRangesText] = useState('');
    const [bulkLacpPortIdText, setBulkLacpPortIdText] = useState('');
    const [bulkLacpPortListText, setBulkLacpPortListText] = useState('');
    const [bulkEnableChoice, setBulkEnableChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkJumboChoice, setBulkJumboChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkRoutingChoice, setBulkRoutingChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkTrunkAllChoice, setBulkTrunkAllChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkAdminEdgeChoice, setBulkAdminEdgeChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkAdminEdgeTrunkChoice, setBulkAdminEdgeTrunkChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkBpduGuardChoice, setBulkBpduGuardChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkLoopGuardChoice, setBulkLoopGuardChoice] = useState<string>(BULK_BOOL_NOOP);
    const [bulkInterfaceModeChoice, setBulkInterfaceModeChoice] = useState<string>(BULK_ENUM_NOOP);
    const [bulkLacpModeChoice, setBulkLacpModeChoice] = useState<string>(BULK_ENUM_NOOP);
    const [bulkLacpRateChoice, setBulkLacpRateChoice] = useState<string>(BULK_ENUM_NOOP);
    const [bulkLacpTrunkTypeChoice, setBulkLacpTrunkTypeChoice] = useState<string>(BULK_ENUM_NOOP);

    const allInterfaceIds = useMemo(() => interfaces.map((r) => r.id), [interfaces]);

    const errorsFingerprint = useMemo(() => JSON.stringify(errors ?? {}), [errors]);

    useEffect(() => {
        setDismissedFieldErrors(new Set());
    }, [errorsFingerprint]);

    useEffect(() => {
        if (!isEditing) {
            setSelectedInterfaceIds(new Set());
            setBulkDescriptionText('');
            setBulkIpAddressText('');
            setBulkVrfForwardingText('');
            setBulkSwProfileText('');
            setBulkPortchannelLagText('');
            setBulkAccessVlanText('');
            setBulkNativeVlanText('');
            setBulkTrunkVlanRangesText('');
            setBulkLacpPortIdText('');
            setBulkLacpPortListText('');
            setBulkEnableChoice(BULK_BOOL_NOOP);
            setBulkJumboChoice(BULK_BOOL_NOOP);
            setBulkRoutingChoice(BULK_BOOL_NOOP);
            setBulkTrunkAllChoice(BULK_BOOL_NOOP);
            setBulkAdminEdgeChoice(BULK_BOOL_NOOP);
            setBulkAdminEdgeTrunkChoice(BULK_BOOL_NOOP);
            setBulkBpduGuardChoice(BULK_BOOL_NOOP);
            setBulkLoopGuardChoice(BULK_BOOL_NOOP);
            setBulkInterfaceModeChoice(BULK_ENUM_NOOP);
            setBulkLacpModeChoice(BULK_ENUM_NOOP);
            setBulkLacpRateChoice(BULK_ENUM_NOOP);
            setBulkLacpTrunkTypeChoice(BULK_ENUM_NOOP);
        }
    }, [isEditing]);

    const dismissFieldErrorForCell = useCallback(
        (interfaceId: number, field: keyof InterfaceDraftRow | string) => {
            const key = dismissedCellErrorKey(interfaceId, String(field));
            setDismissedFieldErrors((prev) => {
                if (prev.has(key)) {
                    return prev;
                }
                const next = new Set(prev);
                next.add(key);
                return next;
            });
        },
        [],
    );

    const setDraftField = useCallback(
        (id: number, key: keyof InterfaceDraftRow, value: unknown) => {
            dismissFieldErrorForCell(id, key);
            setDrafts((current) => ({
                ...current,
                [id]: {
                    ...current[id],
                    [key]: value,
                },
            }));
        },
        [dismissFieldErrorForCell],
    );

    const clearDraftField = useCallback(
        (id: number, key: keyof InterfaceDraftRow) => {
            dismissFieldErrorForCell(id, key);
            setDrafts((current) => {
                const prevRow = current[id];
                if (!prevRow) {
                    return current;
                }
                const row = { ...prevRow };
                delete row[key];
                const next = { ...current };
                if (Object.keys(row).length === 0) {
                    delete next[id];
                } else {
                    next[id] = row;
                }
                return next;
            });
        },
        [dismissFieldErrorForCell],
    );

    const draftsRef = useRef(drafts);
    draftsRef.current = drafts;

    const getDraftValue = useCallback(
        <T,>(row: DeviceInterfaceRow, key: keyof InterfaceDraftRow, fallback: T): T => {
            const rowDraft = draftsRef.current[row.id];
            if (!rowDraft || !(key in rowDraft)) {
                return fallback;
            }
            return rowDraft[key] as T;
        },
        [],
    );

    const toggleSelect = useCallback((id: number) => {
        setSelectedInterfaceIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }, []);

    const toggleSelectAll = useCallback(() => {
        setSelectedInterfaceIds((prev) => {
            if (prev.size === allInterfaceIds.length) {
                return new Set();
            }
            return new Set(allInterfaceIds);
        });
    }, [allInterfaceIds]);

    const allRowsSelected =
        allInterfaceIds.length > 0 && selectedInterfaceIds.size === allInterfaceIds.length;
    const someRowsSelected =
        selectedInterfaceIds.size > 0 && selectedInterfaceIds.size < allInterfaceIds.length;

    const applyBoolToSelected = (key: 'enable' | 'jumbo_frames' | 'routing', value: boolean) => {
        for (const id of selectedInterfaceIds) {
            dismissFieldErrorForCell(id, key);
        }
        setDrafts((current) => {
            const next = { ...current };
            for (const id of selectedInterfaceIds) {
                next[id] = { ...next[id], [key]: value };
            }
            return next;
        });
    };

    const applyFieldToSelected = (key: keyof InterfaceDraftRow, value: unknown) => {
        for (const id of selectedInterfaceIds) {
            dismissFieldErrorForCell(id, key);
        }
        setDrafts((current) => {
            const next = { ...current };
            for (const id of selectedInterfaceIds) {
                next[id] = { ...next[id], [key]: value };
            }
            return next;
        });
    };

    const clearFieldForSelected = (key: keyof InterfaceDraftRow) => {
        for (const id of selectedInterfaceIds) {
            dismissFieldErrorForCell(id, key);
        }
        setDrafts((current) => {
            const next = { ...current };
            for (const id of selectedInterfaceIds) {
                next[id] = { ...next[id], [key]: null };
            }
            return next;
        });
    };

    const applyDescriptionToSelected = () => {
        for (const id of selectedInterfaceIds) {
            dismissFieldErrorForCell(id, 'description');
        }
        setDrafts((current) => {
            const next = { ...current };
            for (const id of selectedInterfaceIds) {
                next[id] = { ...next[id], description: bulkDescriptionText };
            }
            return next;
        });
    };

    const applyInterfaceModeToSelected = (mode: string) => {
        if (mode === 'ACCESS' && bulkAccessVlanText.trim() === '') {
            return;
        }
        if (mode === 'TRUNK' && bulkNativeVlanText.trim() === '') {
            return;
        }
        applyFieldToSelected('interface_mode', mode);
    };

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

    const fieldErrorsByInterfaceId = useMemo(() => {
        const raw = mapValidationErrorsToRows(
            errors,
            pendingUpdates.map((u) => u.id as number),
        );
        return filterFieldErrorsByDismissed(raw, dismissedFieldErrors);
    }, [errors, pendingUpdates, dismissedFieldErrors]);

    const fieldErrorsRef = useRef(fieldErrorsByInterfaceId);
    fieldErrorsRef.current = fieldErrorsByInterfaceId;

    const getFieldError = useCallback((interfaceId: number, field: string) => {
        return fieldErrorsRef.current.get(interfaceId)?.[field];
    }, []);

    const batchUpdateError =
        errors && typeof errors.updates === 'string' ? errors.updates : undefined;

    const interfaceColumns = useMemo(
        () =>
            createInterfaceColumns({
                editing: isEditing,
                getDraftValue,
                setDraftField,
                clearDraftField,
                getFieldError,
                allInterfaceIds,
                selectedInterfaceIds,
                toggleSelect,
                toggleSelectAll,
                allRowsSelected,
                someRowsSelected,
            }),
        [
            isEditing,
            getDraftValue,
            setDraftField,
            clearDraftField,
            getFieldError,
            allInterfaceIds,
            selectedInterfaceIds,
            toggleSelect,
            toggleSelectAll,
            allRowsSelected,
            someRowsSelected,
        ],
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
        () =>
            buildVisibilityPreset(
                allLeafColumnIds,
                new Set(allLeafColumnIds.filter((id) => !INTERFACE_TABLE_HIDDEN_BY_DEFAULT_IDS.has(id))),
            ),
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

    const saveDrafts = () => {
        setIsSaving(true);
        router.patch(
            `/devices/${device.id}/interfaces`,
            { updates: pendingUpdates } as Parameters<typeof router.patch>[1],
            {
                preserveScroll: true,
                onFinish: () => setIsSaving(false),
                onSuccess: () => {
                    setDrafts({});
                    setIsEditing(false);
                },
            },
        );
    };

    const discardEdits = () => {
        setDrafts({});
        router.get(showDevice(device.id).url, {}, { preserveScroll: true, replace: true });
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
                                        onClick={discardEdits}
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
                    {isEditing ? (
                        <p className="text-muted-foreground mb-2 text-sm">
                            Enum fields use <span className="font-medium">Unchanged</span> to keep the
                            server value for that column. VLAN and LACP port ID accept numeric input.
                        </p>
                    ) : null}
                    {isEditing && selectedInterfaceIds.size > 0 ? (
                        <div className="bg-muted/40 mb-3 rounded-md border px-3 py-2 text-sm">
                            <div className="mb-2 border-b pb-2">
                                <span className="text-muted-foreground text-xs font-medium">
                                    Apply to {selectedInterfaceIds.size} selected
                                </span>
                            </div>
                            <div className="max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                                <section className="space-y-2 rounded-md border bg-background/60 p-3">
                                    <h3 className="text-sm font-medium">Core fields</h3>
                                    <div className="space-y-2">
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Enabled</span>
                                            <Select value={bulkEnableChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyBoolToSelected('enable', v === 'true');
                                                setBulkEnableChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Jumbo frames</span>
                                            <Select value={bulkJumboChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyBoolToSelected('jumbo_frames', v === 'true');
                                                setBulkJumboChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Routing</span>
                                            <Select value={bulkRoutingChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyBoolToSelected('routing', v === 'true');
                                                setBulkRoutingChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Description</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkDescriptionText} onChange={(e) => setBulkDescriptionText(e.target.value)} placeholder="Same for all selected" />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={applyDescriptionToSelected}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => clearFieldForSelected('description')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">IP address</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkIpAddressText} onChange={(e) => setBulkIpAddressText(e.target.value)} placeholder="e.g. 192.0.2.10/24" />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('ip_address', bulkIpAddressText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => clearFieldForSelected('ip_address')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">VRF forwarding</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkVrfForwardingText} onChange={(e) => setBulkVrfForwardingText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('vrf_forwarding', bulkVrfForwardingText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('vrf_forwarding', 'default')}>Reset</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Port profile</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkSwProfileText} onChange={(e) => setBulkSwProfileText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('sw_profile', bulkSwProfileText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => clearFieldForSelected('sw_profile')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Portchannel/LAG</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkPortchannelLagText} onChange={(e) => setBulkPortchannelLagText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('portchannel_lag', bulkPortchannelLagText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => clearFieldForSelected('portchannel_lag')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                    </div>
                                </section>

                                <section className="space-y-2 rounded-md border bg-background/60 p-3">
                                    <h3 className="text-sm font-medium">Switchport profile</h3>
                                    <div className="space-y-2">
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Port mode</span>
                                            <Select value={bulkInterfaceModeChoice} onValueChange={(v) => {
                                                if (v === BULK_ENUM_NOOP) return;
                                                applyInterfaceModeToSelected(v);
                                                setBulkInterfaceModeChoice(BULK_ENUM_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_ENUM_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    {INTERFACE_MODES.map((opt) => <SelectItem key={opt} value={opt}>{opt}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Access VLAN</span>
                                            <div className="flex items-center gap-2">
                                                <Input className="h-8 w-[8rem] text-sm" type="number" min={1} max={4094} value={bulkAccessVlanText} onChange={(e) => setBulkAccessVlanText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8" onClick={() => applyFieldToSelected('access_vlan', bulkAccessVlanText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => clearFieldForSelected('access_vlan')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Native VLAN</span>
                                            <div className="flex items-center gap-2">
                                                <Input className="h-8 w-[8rem] text-sm" type="number" min={1} max={4094} value={bulkNativeVlanText} onChange={(e) => setBulkNativeVlanText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8" onClick={() => applyFieldToSelected('native_vlan', bulkNativeVlanText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => clearFieldForSelected('native_vlan')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Trunk all VLANs</span>
                                            <Select value={bulkTrunkAllChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyFieldToSelected('trunk_vlan_all', v === 'true');
                                                setBulkTrunkAllChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">Trunk VLAN ranges</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkTrunkVlanRangesText} onChange={(e) => setBulkTrunkVlanRangesText(e.target.value)} placeholder="e.g. 10,20,30-40" />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('trunk_vlan_ranges', bulkTrunkVlanRangesText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => clearFieldForSelected('trunk_vlan_ranges')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                    </div>
                                </section>

                                <section className="space-y-2 rounded-md border bg-background/60 p-3">
                                    <h3 className="text-sm font-medium">LACP profile</h3>
                                    <div className="space-y-2">
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">LACP mode</span>
                                            <Select value={bulkLacpModeChoice} onValueChange={(v) => {
                                                if (v === BULK_ENUM_NOOP) return;
                                                applyFieldToSelected('lacp_mode', v);
                                                setBulkLacpModeChoice(BULK_ENUM_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_ENUM_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    {LACP_MODES.map((opt) => <SelectItem key={opt} value={opt}>{opt}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">LACP rate</span>
                                            <Select value={bulkLacpRateChoice} onValueChange={(v) => {
                                                if (v === BULK_ENUM_NOOP) return;
                                                applyFieldToSelected('lacp_rate', v);
                                                setBulkLacpRateChoice(BULK_ENUM_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_ENUM_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    {LACP_RATES.map((opt) => <SelectItem key={opt} value={opt}>{opt}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">LACP trunk type</span>
                                            <Select value={bulkLacpTrunkTypeChoice} onValueChange={(v) => {
                                                if (v === BULK_ENUM_NOOP) return;
                                                applyFieldToSelected('trunk_type', v);
                                                setBulkLacpTrunkTypeChoice(BULK_ENUM_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[12rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_ENUM_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    {LACP_TRUNK_TYPES.map((opt) => <SelectItem key={opt} value={opt}>{opt}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">LACP port ID</span>
                                            <div className="flex items-center gap-2">
                                                <Input className="h-8 w-[8rem] text-sm" type="number" min={1} value={bulkLacpPortIdText} onChange={(e) => setBulkLacpPortIdText(e.target.value)} />
                                                <Button type="button" variant="secondary" size="sm" className="h-8" onClick={() => applyFieldToSelected('lacp_port_id', bulkLacpPortIdText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => clearFieldForSelected('lacp_port_id')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">LACP port list</span>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input className="h-8 min-w-[10rem] flex-1 text-sm" value={bulkLacpPortListText} onChange={(e) => setBulkLacpPortListText(e.target.value)} placeholder="e.g. 1/1/1, 1/1/2" />
                                                <Button type="button" variant="secondary" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('lacp_port_list', bulkLacpPortListText)}>Apply</Button>
                                                <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={() => applyFieldToSelected('lacp_port_list', '')}>Clear</Button>
                                            </div>
                                        </FieldWrap>
                                    </div>
                                </section>

                                <section className="space-y-2 rounded-md border bg-background/60 p-3">
                                    <h3 className="text-sm font-medium">STP profile</h3>
                                    <div className="space-y-2">
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">STP admin edge</span>
                                            <Select value={bulkAdminEdgeChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyFieldToSelected('admin_edge_port', v === 'true');
                                                setBulkAdminEdgeChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">STP edge trunk</span>
                                            <Select value={bulkAdminEdgeTrunkChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyFieldToSelected('admin_edge_port_trunk', v === 'true');
                                                setBulkAdminEdgeTrunkChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">STP BPDU guard</span>
                                            <Select value={bulkBpduGuardChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyFieldToSelected('bpdu_guard', v === 'true');
                                                setBulkBpduGuardChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                        <FieldWrap className="min-w-0 space-y-1">
                                            <span className="text-muted-foreground text-xs">STP loop guard</span>
                                            <Select value={bulkLoopGuardChoice} onValueChange={(v) => {
                                                if (v === BULK_BOOL_NOOP) return;
                                                applyFieldToSelected('loop_guard', v === 'true');
                                                setBulkLoopGuardChoice(BULK_BOOL_NOOP);
                                            }}>
                                                <SelectTrigger className="h-8 w-[10rem] text-xs"><SelectValue placeholder="No change" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={BULK_BOOL_NOOP} className="text-muted-foreground">No change</SelectItem>
                                                    <SelectItem value="true">Yes</SelectItem>
                                                    <SelectItem value="false">No</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </FieldWrap>
                                    </div>
                                </section>
                            </div>
                        </div>
                    ) : null}
                    {batchUpdateError ? (
                        <div
                            className="border-destructive/30 bg-destructive/10 text-destructive mb-3 rounded-md border px-3 py-2 text-sm"
                            role="alert"
                        >
                            {batchUpdateError}
                        </div>
                    ) : null}
                    <DataTable<DeviceInterfaceRow, unknown>
                        data={interfaces}
                        columns={interfaceColumns}
                        getRowId={(row) => String(row.id)}
                        stickyLeftColumnIds={isEditing ? ['select', 'interface'] : ['interface']}
                        enableColumnPicker
                        columnPickerTitle="Columns"
                        columnVisibility={columnVisibility}
                        onColumnVisibilityChange={setColumnVisibility}
                        columnGroups={[
                            {
                                label: 'Core',
                                columnIds: [
                                    'select',
                                    'id',
                                    'interface',
                                    'description',
                                    'ip_address',
                                    'enable',
                                    'jumbo_frames',
                                    'routing',
                                    'vrf_forwarding',
                                    'sw_profile',
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
