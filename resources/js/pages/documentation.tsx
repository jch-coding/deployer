import { Head } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import Layout from '@/layouts/app-layout';
import { downloadSampleDeviceCsv } from '@/lib/sample-device-csv';

function ColumnPair({
    required,
    optional,
}: {
    required: ReactNode[];
    optional?: ReactNode[];
}) {
    const hasOptional = optional && optional.length > 0;
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <p className="font-medium">Required columns</p>
                <ul className="mt-2 list-inside list-disc text-sm">
                    {required.map((item, i) => (
                        <li key={i}>{item}</li>
                    ))}
                </ul>
            </div>
            <div>
                <p className="font-medium">Optional columns</p>
                {hasOptional ? (
                    <ul className="mt-2 list-inside list-disc text-sm">
                        {optional!.map((item, i) => (
                            <li key={i}>{item}</li>
                        ))}
                    </ul>
                ) : (
                    <p className="mt-2 text-muted-foreground text-sm">No optional columns</p>
                )}
            </div>
        </div>
    );
}

function DocCard({
    title,
    badge,
    defaultOpen = true,
    className,
    children,
}: {
    title: ReactNode;
    badge?: ReactNode;
    defaultOpen?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Collapsible
            defaultOpen={defaultOpen}
            className={
                'flex h-full min-h-[280px] flex-col [&[data-state=open]_.doc-chevron]:rotate-180' +
                (className ? ` ${className}` : '')
            }
        >
            <Card className="flex h-full flex-col gap-0 border py-0 shadow-sm">
                <CardHeader className="flex flex-row items-start justify-between gap-2 border-b px-6 py-4">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                            <span className="font-bold leading-tight">{title}</span>
                            {badge}
                        </div>
                    </div>
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="shrink-0"
                            aria-label="Expand or collapse section"
                        >
                            <ChevronDown
                                className="doc-chevron size-4 transition-transform"
                                aria-hidden
                            />
                        </Button>
                    </CollapsibleTrigger>
                </CardHeader>
                <CollapsibleContent>
                    <CardContent className="max-h-[60vh] overflow-y-auto px-6 pt-4 pb-6">
                        {children}
                    </CardContent>
                </CollapsibleContent>
            </Card>
        </Collapsible>
    );
}

type CsvColumnDetail = {
    column: string;
    type: string;
    accepted: ReactNode;
};

const CSV_COLUMN_DETAILS: CsvColumnDetail[] = [
    {
        column: 'name',
        type: 'string',
        accepted: 'Device name (example: ACC-SWITCH-1).',
    },
    {
        column: 'serial',
        type: 'string',
        accepted: 'Device serial number.',
    },
    {
        column: 'device_function',
        type: 'enum',
        accepted:
            'CAMPUS_AP, CORE_SWITCH, AGG_SWITCH, BRANCH_GW, MOBILITY_GW, VPNC, MICROBRANCH_AP, ACCESS_SWITCH, AOSS_ACCESS_SWITCH, AOSS_CORE_SWITCH, AOSS_AGG_SWITCH, ALL.',
    },
    {
        column: 'interface',
        type: 'string (single value or range expression)',
        accepted: (
            <>
                Single interface, or interface ranges separated by <code>&amp;</code>. Examples:{' '}
                <code>1/1/1</code>, <code>1/1/1-1/1/48</code>, <code>1/1/1&amp;1/1/6-1/1/48</code>.
            </>
        ),
    },
    {
        column: 'port_list',
        type: 'string (range expression)',
        accepted: (
            <>
                LAG member ports. Uses the same range syntax as <code>interface</code>. Examples:{' '}
                <code>1/1/1-1/1/3</code>, <code>1/1/51&amp;2/1/51</code>.
            </>
        ),
    },
    {
        column: 'trunk_vlan_ranges',
        type: 'string (range expression)',
        accepted: (
            <>
                VLAN ranges separated by <code>&amp;</code>. Examples: <code>10-20</code>,{' '}
                <code>10-20&amp;30-40</code>.
            </>
        ),
    },
    {
        column: 'interface_mode',
        type: 'enum',
        accepted: 'ACCESS, TRUNK.',
    },
    {
        column: 'lacp_mode',
        type: 'enum',
        accepted: 'ACTIVE, PASSIVE, AUTO.',
    },
    {
        column: 'lacp_rate',
        type: 'enum',
        accepted: 'FAST, SLOW.',
    },
    {
        column: 'trunk_type',
        type: 'enum',
        accepted: 'LACP, TRUNK, DT_TRUNK, MULTI_CHASSIS, MULTI_CHASSIS_STATIC.',
    },
    {
        column: 'access_vlan',
        type: 'integer',
        accepted: 'VLAN ID (typically 1-4094). Example: 10.',
    },
    {
        column: 'native_vlan',
        type: 'integer',
        accepted: 'VLAN ID (typically 1-4094). Example: 20.',
    },
    {
        column: 'ip_address',
        type: 'string',
        accepted: 'IP/CIDR notation. Example: 192.168.1.1/24.',
    },
    {
        column: 'vrf_forwarding',
        type: 'string',
        accepted: 'VRF name for routed ethernet interfaces. Optional. Example: default.',
    },
    {
        column: 'port_profile',
        type: 'string',
        accepted: 'Port profile name to apply.',
    },
    {
        column: 'description',
        type: 'string',
        accepted: 'Interface description text.',
    },
    {
        column: 'group',
        type: 'string',
        accepted: 'Central group name.',
    },
    {
        column: 'site',
        type: 'string',
        accepted: 'Central site name.',
    },
    {
        column: 'sku',
        type: 'string',
        accepted: 'Switch SKU (used for VSF profile creation). Example: JL660A.',
    },
    {
        column: 'vsx_profile',
        type: 'string',
        accepted: 'Name of the VSX profile. Both peers in a pair must share the same value.',
    },
    {
        column: 'vsx_role',
        type: 'enum',
        accepted: 'VSX_PRIMARY or VSX_SECONDARY. Each VSX profile requires exactly one of each.',
    },
    {
        column: 'vsx_system_mac',
        type: 'string',
        accepted: 'System MAC in the form 02:00:00:00:00:xx where xx are hex digits starting from 01. Same value on both peers.',
    },
    {
        column: 'trunk_vlan_all',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive).
            </>
        ),
    },
    {
        column: 'admin_edge_port',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive).
            </>
        ),
    },
    {
        column: 'admin_edge_port_trunk',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive).
            </>
        ),
    },
    {
        column: 'bpdu_guard',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive).
            </>
        ),
    },
    {
        column: 'loop_guard',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive).
            </>
        ),
    },
    {
        column: 'shutdown_on_split',
        type: 'boolean',
        accepted: (
            <>
                <code>true</code> or <code>false</code> (case sensitive). Applies VSX shutdown behavior
                for split-brain handling on supported ethernet interfaces.
            </>
        ),
    },
];

export default function documentation() {
    return (
        <Layout>
            <Head title="CSV column details" />
            <div className="mx-auto max-w-7xl px-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div className="md:col-span-2 lg:col-span-3">
                        <DocCard title="CSV required headers" defaultOpen>
                            <div className="space-y-4">
                                <p>
                                    There are three pieces of information that are required for all
                                    devices: name, serial and device_function
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="gap-2"
                                    onClick={downloadSampleDeviceCsv}
                                >
                                    <Download className="size-4" aria-hidden />
                                    Download sample CSV
                                </Button>
                                <p className="text-muted-foreground text-sm">
                                    Example rows illustrate common column combinations. Replace names,
                                    serials, and values with data that matches your Aruba Central
                                    configuration.
                                </p>
                            </div>
                        </DocCard>
                    </div>

                    <DocCard title="Name Device" defaultOpen>
                        <ColumnPair
                            required={['name', 'serial', 'device_function']}
                            optional={[]}
                        />
                    </DocCard>

                    <DocCard title="Configure Ethernet Interfaces" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                The interface column can be a single interface (in the form of x/x/x) or
                                a range of interfaces or a set of interface ranges separated by an &
                                symbol
                            </p>
                            <p>
                                <i>ex: 1/1/1 or 1/1/1-1/1/48 or 1/1/1&1/1/6-1/1/48</i>
                            </p>
                            <p>
                                When <code>ip_address</code> is set on an ethernet interface, only{' '}
                                <code>interface</code>, <code>description</code>, <code>ip_address</code>, and{' '}
                                <code>vrf_forwarding</code> may be used. Switchport, LAG, and port profile columns
                                cannot appear on the same row.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'interface']}
                                optional={[
                                    'port_profile',
                                    'description',
                                    'ip_address',
                                    'vrf_forwarding',
                                    'interface_mode',
                                    'access_vlan',
                                    'native_vlan',
                                    'trunk_vlan_all',
                                    'trunk_vlan_ranges',
                                    'admin_edge_port',
                                    'admin_edge_port_trunk',
                                    'bpdu_guard',
                                    'loop_guard',
                                    'shutdown_on_split',
                                    'lacp_mode',
                                    'lacp_rate',
                                    'trunk_type',
                                    'port_list',
                                ]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Configure Portchannel/LAG Interface" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Configuring LAG interfaces only depends on the port_list column for
                                defining physical link members. The individual member interfaces do NOT
                                require an individual row in the CSV file.
                            </p>
                            <p>
                                The interface column should be a single number that will be the LAG ID
                                that will be configured
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    'interface',
                                    <>
                                        port_list (<i>ex: 1/1/1-1/1/3 or 1/1/51&2/1/51</i>)
                                    </>,
                                ]}
                                optional={[
                                    'port_profile',
                                    'interface_mode',
                                    'access_vlan',
                                    'native_vlan',
                                    'trunk_vlan_all',
                                    'trunk_vlan_ranges',
                                    <>
                                        trunk_type (<i>default</i> LACP, TRUNK, DT_TRUNK, MULTI_CHASSIS,
                                        MULTI_CHASSIS_STATIC)
                                    </>,
                                ]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Configure SVI" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                The interface column should be a single number that corresponds to the
                                VLAN for which the SVI will be configured
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    <>
                                        interface (<i>ex: 11</i>)
                                    </>,
                                    <>
                                        ip_address (<i>ex: 192.168.1.1/24</i>)
                                    </>,
                                ]}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Configure LAG, Ethernet and VLAN Interfaces" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Multi-job task <code className="rounded bg-muted px-1 py-0.5 text-sm">
                                    CONFIGURE_ALL_INTERFACE
                                </code>
                                . Runs <strong>Configure Portchannel/LAG interface</strong>, then{' '}
                                <strong>Configure Ethernet Interfaces</strong>, then{' '}
                                <strong>Configure SVI</strong>, in that order, using the same interface rows
                                from your CSV.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Include optional columns needed for each layer (for example{' '}
                                <span className="font-mono text-xs">port_list</span> for LAG rows) per the
                                standalone cards above.
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    'interface',
                                    <>
                                        ip_address (<i>ex: 192.168.1.1/24</i>)
                                    </>,
                                ]}
                                optional={[
                                    <>
                                        port_list (<i>required for LAG rows; ex: 1/1/1-1/1/3</i>)
                                    </>,
                                    'port_profile',
                                    'description',
                                    'ip_address',
                                    'interface_mode',
                                    'access_vlan',
                                    'native_vlan',
                                    'trunk_vlan_all',
                                    'trunk_vlan_ranges',
                                    'admin_edge_port',
                                    'admin_edge_port_trunk',
                                    'bpdu_guard',
                                    'loop_guard',
                                    'shutdown_on_split',
                                    'lacp_mode',
                                    'lacp_rate',
                                    <>
                                        trunk_type (<i>LACP, TRUNK, DT_TRUNK, MULTI_CHASSIS,</i> etc.)
                                    </>,
                                ]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Configure VSF Profile" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                The sku column should be included for the conductor switch ONLY. The
                                VSF profile name will be the name of the conductor plus -STACK appended.
                                Note that this creates an auto-stacking VSF profile only.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'sku']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Create VSX Profile" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Creates a VSX profile for a pair of switches. Both peers must share the same{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">vsx_profile</code> and{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">vsx_system_mac</code>, with one{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">VSX_PRIMARY</code> and one{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">VSX_SECONDARY</code>. The{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">group</code> column is required
                                so the task can ensure the WHSE-VSX-Keep-Alive VRF at device group scope before creating
                                LAG 256 (inter-switch-link) and LAG 255 (keepalive) interfaces.
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    'group',
                                    'vsx_profile',
                                    'vsx_role',
                                    'vsx_system_mac',
                                ]}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Remove VSF profile local overrides" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Multi-job task{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">
                                    REMOVE_VSF_PROFILE_LOCAL_OVERRIDES
                                </code>
                                . Device-based: clears local overrides introduced during VSF onboarding by
                                running remove tasks for VLANs, DNS profile, static routes, NTP profile, and
                                local management profile in sequence. Choose VSF devices only (has SKU) or all
                                selected devices when launching the task.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Select devices on the deployment when starting the task; CSV rows identify
                                devices only (no interface columns).
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Associate Devices to Site"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                The site column should be included for all devices that need to be
                                associated with a site. The site name should be in the site column and
                                must match the site name configured in Central.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'site']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Associate Devices to Site and Name"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                The site and name columns should be included for all devices that need to
                                be associated with a site and a name. The site name should be in the site
                                column and must match the site name configured in Central. The name column
                                should be the name of the device that will be configured in the device
                                name column.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'site']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Preprovision Devices to Group"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                The group column should be included for all devices that need to be
                                preprovisioned to a group. The group name should be in the group column
                                and must match the group name configured in Central.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'group']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Move Devices to Device Group"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                The group column should be included for all devices that need to be moved
                                to a group. The group name should be in the group column and must match
                                the group name configured in Central.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'group']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Assign Device Function to Devices" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                The device_function column should be included for all devices that need to
                                be assigned a device function. The device function name should be in the
                                device_function column and must match the device function name configured
                                in Central.
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    <>
                                        device_function (
                                        <i>
                                            CAMPUS_AP, CORE_SWITCH, AGG_SWITCH, BRANCH_GW, MOBILITY_GW,
                                            VPNC, MICROBRANCH_AP, ACCESS_SWITCH
                                        </i>
                                        )
                                    </>,
                                ]}
                                optional={[]}
                            />
                        </div>
                    </DocCard>
                </div>
                <div className="mt-6">
                    <DocCard title="CSV column data types and accepted values" defaultOpen>
                        <div className="space-y-4">
                            <p className="text-sm">
                                This section defines the expected datatype for each CSV column. For
                                boolean columns, values are case-sensitive and must be{' '}
                                <code>true</code> or <code>false</code>.
                            </p>
                            <ul className="space-y-3 text-sm">
                                {CSV_COLUMN_DETAILS.map((detail) => (
                                    <li key={detail.column} className="rounded-md border px-3 py-2">
                                        <p className="font-mono text-xs font-semibold">{detail.column}</p>
                                        <p className="text-muted-foreground text-xs">
                                            Type: {detail.type}
                                        </p>
                                        <p className="mt-1">{detail.accepted}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </DocCard>
                </div>
            </div>
        </Layout>
    );
}
