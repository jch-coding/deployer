import { Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Eye, MoreHorizontal, Pencil, RefreshCw, TrashIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    destroy as deleteDevice,
    edit as editDevice,
    refreshScopeId,
    show as showDevice,
} from '@/routes/devices';

type CentralScopeOption = {
    scopeName: string;
    scopeId: string;
    isClassic?: boolean;
};

export type DeviceDef = {
    id: number;
    name: string;
    serial: string | number;
    model?: string | null;
    device_function: string;
    mac_address?: string | null;
    site?: string | null;
    group?: string | null;
    interfaces?: {
        id: number;
        interface: string;
        ip_address?: string;
        sw_profile?: string;
        description?: string;
        lacp_profile_id?: number;
    }[];
};

type DeploymentShowColumnOptions = {
    centralSites: CentralScopeOption[];
    deviceGroupOptions: CentralScopeOption[];
    centralSitesError: string | null;
    centralDeviceGroupsError: string | null;
};

function EditableDeviceNameCell({ id, name }: { id: number; name: string }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(name);

    const onBlur = () => {
        if (draft === name) {
            setEditing(false);
            return;
        }
        router.put(editDevice(id).url, { name: draft });
        setEditing(false);
    };

    return editing ? (
        <Input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={onBlur}
        />
    ) : (
        <p className="group flex items-baseline justify-between gap-2">
            <span className="font-medium">{name}</span>
            <span className="shrink-0">
                <Button
                    type="button"
                    onClick={() => {
                        setDraft(name);
                        setEditing(true);
                    }}
                    variant="ghost"
                    className="opacity-0 group-hover:opacity-100"
                >
                    <Pencil />
                </Button>
            </span>
        </p>
    );
}

function EditableDeviceSerialCell({
    id,
    serial,
}: {
    id: number;
    serial: string | number;
}) {
    const serverSerial = String(serial ?? '');
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(serverSerial);

    const onBlur = () => {
        if (draft === serverSerial) {
            setEditing(false);
            return;
        }
        router.put(editDevice(id).url, { serial: draft });
        setEditing(false);
    };

    return editing ? (
        <Input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={onBlur}
        />
    ) : (
        <p className="group flex items-baseline justify-between">
            {serverSerial}
            <span>
                <Button
                    type="button"
                    onClick={() => {
                        setDraft(serverSerial);
                        setEditing(true);
                    }}
                    variant="ghost"
                    className="opacity-0 group-hover:opacity-100"
                    aria-label="Edit serial"
                >
                    <Pencil />
                </Button>
            </span>
        </p>
    );
}

function DeviceMetadataSelectCell({
    deviceId,
    field,
    value,
    options,
    centralError,
    showClassicTag = false,
}: {
    deviceId: number;
    field: 'site' | 'group';
    value: string | null;
    options: CentralScopeOption[];
    centralError: string | null;
    showClassicTag?: boolean;
}) {
    const [saving, setSaving] = useState(false);
    const mergedOptions = useMemo(() => {
        const trimmed = value?.trim();
        if (!trimmed || options.some((option) => option.scopeName === trimmed)) {
            return options;
        }

        return [...options, { scopeName: trimmed, scopeId: '' }];
    }, [options, value]);

    const editable = centralError === null && !saving;
    const selectValue = value?.trim() || '__none__';

    const handleChange = (next: string) => {
        const newValue = next === '__none__' ? null : next;
        const currentValue = value?.trim() || null;
        if (currentValue === newValue) {
            return;
        }

        setSaving(true);
        router.patch(
            `/devices/${deviceId}`,
            { [field]: newValue },
            {
                preserveScroll: true,
                only: ['devices'],
                onFinish: () => setSaving(false),
            },
        );
    };

    if (centralError && options.length === 0) {
        return (
            <span
                className="text-muted-foreground text-xs"
                title={centralError}
            >
                {value?.trim() || '—'}
            </span>
        );
    }

    return (
        <Select
            value={selectValue}
            disabled={!editable}
            onValueChange={handleChange}
        >
            <SelectTrigger
                className="h-8 min-w-[8rem] text-sm"
                aria-label={field}
                data-test={`device-${field}-select-${deviceId}`}
            >
                <SelectValue placeholder="None" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__none__">None</SelectItem>
                {mergedOptions.map((option) => (
                    <SelectItem
                        key={option.scopeName}
                        value={option.scopeName}
                    >
                        {showClassicTag && option.isClassic ? (
                            <span className="flex items-center gap-2">
                                {option.scopeName}
                                <Badge
                                    variant="outline"
                                    className="text-xs font-normal"
                                >
                                    classic
                                </Badge>
                            </span>
                        ) : (
                            option.scopeName
                        )}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function DeviceScopeAndDeleteActions({ id }: { id: number }) {
    const [refreshing, setRefreshing] = useState(false);

    return (
        <div className="flex items-center justify-end gap-1">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        asChild
                        variant="outline"
                        aria-label="View device"
                        data-test="device-show-link"
                    >
                        <Link href={showDevice(id).url}>
                            <Eye className="size-4" aria-hidden />
                        </Link>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>View device</TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={refreshing}
                            aria-label="refresh device scope ID"
                            data-test="refresh-device-scope-id"
                            onClick={() => {
                                setRefreshing(true);
                                router.put(refreshScopeId(id).url, {}, {
                                    preserveScroll: true,
                                    onFinish: () => setRefreshing(false),
                                });
                            }}
                        >
                            <RefreshCw
                                className={`size-4 ${refreshing ? 'animate-spin' : ''}`}
                                aria-hidden
                            />
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>refresh device scope ID</TooltipContent>
            </Tooltip>
            <Button
                variant="outline"
                className="hover:bg-red-500 hover:text-white"
                aria-label="Delete device"
                onClick={() => router.delete(deleteDevice(id))}
            >
                <TrashIcon />
            </Button>
        </div>
    );
}

export const deploymentShowSelectColumn: ColumnDef<DeviceDef> = {
    id: 'select',
    header: ({ table }) => (
        <Checkbox
            checked={
                table.getIsAllPageRowsSelected() ||
                (table.getIsSomePageRowsSelected() && 'indeterminate')
            }
            onCheckedChange={(value) =>
                table.toggleAllPageRowsSelected(value === true)
            }
            aria-label="Select all devices on this page"
            data-test="select-all-devices-on-page"
        />
    ),
    cell: ({ row }) => (
        <Checkbox
            checked={row.getIsSelected()}
            onCheckedChange={(value) => row.toggleSelected(value === true)}
            aria-label={`Select device ${row.original.name}`}
            data-test={`select-device-${row.original.id}`}
        />
    ),
    enableSorting: false,
    enableHiding: false,
};

const sharedDeviceColumns: ColumnDef<DeviceDef>[] = [
    {
        accessorKey: 'name',
        header: 'Name',
        cell: ({ row }) => (
            <EditableDeviceNameCell
                id={row.original.id}
                name={row.original.name}
            />
        ),
    },
    {
        accessorKey: 'serial',
        header: 'Serial',
        cell: ({ row }) => (
            <EditableDeviceSerialCell
                id={row.original.id}
                serial={row.original.serial}
            />
        ),
    },
    {
        accessorKey: 'device_function',
        header: 'Device Function',
    },
];

const deviceActionsColumn: ColumnDef<DeviceDef> = {
    id: 'delete',
    cell: ({ row }) => <DeviceScopeAndDeleteActions id={row.original.id} />,
};

const interfacesColumn: ColumnDef<DeviceDef> = {
    id: 'actions',
    header: 'Interfaces',
    cell: ({ row }) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="h-8 w-8 p-0"
                    data-test="actions-open"
                >
                    <span className="sr-only">Open menu</span>
                    <MoreHorizontal className="h-5 w-5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-24 overflow-y-scroll"
            >
                <DropdownMenuLabel>Interfaces</DropdownMenuLabel>
                {(row.original.interfaces ?? []).map((device_interface) => (
                    <DropdownMenuItem key={device_interface.id}>
                        {!device_interface.interface.includes('/') &&
                        !device_interface.lacp_profile_id
                            ? 'VLAN'
                            : ''}{' '}
                        {device_interface.lacp_profile_id ? 'LAG' : ''}{' '}
                        {device_interface.interface}{' '}
                        {device_interface.ip_address
                            ? ` - IP: ${device_interface.ip_address}`
                            : ''}{' '}
                        {device_interface.sw_profile
                            ? `(${device_interface.sw_profile})`
                            : ''}{' '}
                        {device_interface.description
                            ? ` - desc: ${device_interface.description}`
                            : ''}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    ),
};

export function createDeploymentShowColumns(
    options: DeploymentShowColumnOptions,
): ColumnDef<DeviceDef>[] {
    return [
        deploymentShowSelectColumn,
        ...sharedDeviceColumns.slice(0, 2),
        {
            accessorKey: 'model',
            header: 'Model',
            cell: ({ row }) => row.original.model ?? '',
        },
        ...sharedDeviceColumns.slice(2),
        {
            accessorKey: 'site',
            header: 'Site',
            cell: ({ row }) => (
                <DeviceMetadataSelectCell
                    deviceId={row.original.id}
                    field="site"
                    value={row.original.site ?? null}
                    options={options.centralSites}
                    centralError={options.centralSitesError}
                />
            ),
        },
        {
            accessorKey: 'group',
            header: 'Group',
            cell: ({ row }) => (
                <DeviceMetadataSelectCell
                    deviceId={row.original.id}
                    field="group"
                    value={row.original.group ?? null}
                    options={options.deviceGroupOptions}
                    centralError={options.centralDeviceGroupsError}
                    showClassicTag
                />
            ),
        },
        deviceActionsColumn,
    ];
}

export const columns: ColumnDef<DeviceDef>[] = [
    {
        accessorKey: 'id',
        header: 'ID',
    },
    ...sharedDeviceColumns,
    interfacesColumn,
    deviceActionsColumn,
];
