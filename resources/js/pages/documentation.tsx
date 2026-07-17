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
            'CAMPUS_AP, CORE_SWITCH, AGG_SWITCH, BRANCH_GW, MOBILITY_GW, VPNC, MICROBRANCH_AP, ACCESS_SWITCH, AOSS_ACCESS_SWITCH, AOSS_CORE_SWITCH, AOSS_AGG_SWITCH, SERVICE_PERSONA, ALL.',
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
        accepted: (
            <>
                Shared VSX profile name for a peer pair. Both switches in the pair must use the same
                value. When launching the task, devices are grouped by this column and one VSX profile
                is created per unique name.
            </>
        ),
    },
    {
        column: 'vsx_role',
        type: 'enum',
        accepted: (
            <>
                <code>VSX_PRIMARY</code> or <code>VSX_SECONDARY</code>. Each VSX profile requires
                exactly one primary and one secondary device.
            </>
        ),
    },
    {
        column: 'vsx_system_mac',
        type: 'string',
        accepted: (
            <>
                System MAC shared by both peers in the form{' '}
                <code>02:00:00:00:00:xx</code> where <code>xx</code> are hex digits starting from{' '}
                <code>01</code>. Colons or dashes are accepted; leading zeros may be omitted (Excel
                often strips them) and are normalized on import.
            </>
        ),
    },
    {
        column: 'mac_address',
        type: 'string',
        accepted: (
            <>
                Optional device MAC for GreenLake inventory onboarding. Normalized to{' '}
                <code>aa:bb:cc:dd:ee:ff</code>. Colons, dashes, or bare hex are accepted. Required
                when launching Add Devices to GreenLake Inventory.
            </>
        ),
    },
    {
        column: 'vsx_isl_ports',
        type: 'string (range expression)',
        accepted: (
            <>
                Optional LAG 256 (inter-switch-link) member ports. Uses the same range syntax as{' '}
                <code>port_list</code> and must expand to exactly two interfaces. Examples:{' '}
                <code>1/1/53-1/1/54</code>, <code>1/1/21&amp;1/1/22</code>. Must be set together with{' '}
                <code>vsx_keepalive_ports</code> on both peers when overriding defaults; both peers
                must use the same values.
            </>
        ),
    },
    {
        column: 'vsx_keepalive_ports',
        type: 'string (range expression)',
        accepted: (
            <>
                Optional LAG 255 (keepalive) member ports. Uses the same range syntax as{' '}
                <code>port_list</code> and must expand to exactly two interfaces. Examples:{' '}
                <code>1/1/47-1/1/48</code>, <code>1/1/23&amp;1/1/24</code>. Must be set together with{' '}
                <code>vsx_isl_ports</code> on both peers when overriding defaults; both peers must use
                the same values.
            </>
        ),
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
    {
        column: 'mirror_session_id',
        type: 'integer',
        accepted: (
            <>
                Mirror session ID for <strong>Configure Mirror Session</strong> when using explicit mirror
                settings. Must be <code>1</code>, <code>2</code>, <code>3</code>, or <code>4</code>. Defaults
                to <code>1</code> when omitted in explicit mode.
            </>
        ),
    },
    {
        column: 'mirror_dst_ports',
        type: 'string (range expression)',
        accepted: (
            <>
                SPAN destination interface(s) for <strong>Configure Mirror Session</strong>. Uses the same
                range syntax as <code>port_list</code>. Examples: <code>1/1/43</code>,{' '}
                <code>1/1/21&amp;1/1/22</code>. Required in explicit mirror mode; omitted in fallback mode
                when the device name matches a supported pattern.
            </>
        ),
    },
    {
        column: 'mirror_vlans',
        type: 'string (range expression)',
        accepted: (
            <>
                VLAN IDs to mirror in explicit mode. Uses the same range syntax as{' '}
                <code>trunk_vlan_ranges</code>. Examples: <code>10-20</code>,{' '}
                <code>100&amp;200-202</code>. When omitted, VLANs are fetched from Central at device scope
                and, if <code>group</code> is set, at group scope (merged and deduplicated).
            </>
        ),
    },
    {
        column: 'mirror_name',
        type: 'string',
        accepted: (
            <>
                Mirror session name in Central for explicit mode. When omitted, defaults to{' '}
                <code>{'{device.name}'}-DARKTRACE-SPAN</code>.
            </>
        ),
    },
];

export default function documentation() {
    return (
        <Layout>
            <Head title="CSV columns" />
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
                                Device-based task that creates a VSX profile for a pair of switches.
                                Upload one CSV row per switch (no interface columns). Both peers must
                                share the same{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">vsx_profile</code>,{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">vsx_system_mac</code>, and{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">site</code>, with one{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">VSX_PRIMARY</code> and one{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">VSX_SECONDARY</code>.
                                Devices with different <code className="rounded bg-muted px-1 py-0.5 text-sm">vsx_profile</code>{' '}
                                values are processed as separate pairs when the task runs.
                            </p>
                            <p>
                                For each peer, the task ensures the WHSE-VSX-Keep-Alive VRF at device
                                group scope, validates or creates LAG 256 (inter-switch-link) and LAG 255
                                (keepalive), sets member port descriptions, then posts the VSX profile at
                                site scope. Keepalive addresses are{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">1.1.1.1/30</code> on the
                                primary and <code className="rounded bg-muted px-1 py-0.5 text-sm">1.1.1.2/30</code>{' '}
                                on the secondary.
                            </p>
                            <p className="font-medium text-sm">Default LAG member ports (when overrides are not set)</p>
                            <ul className="list-inside list-disc text-sm">
                                <li>
                                    Device name contains <code>CORE</code>: LAG 256{' '}
                                    <code>1/1/53-1/1/54</code>, LAG 255 <code>1/1/47-1/1/48</code>
                                </li>
                                <li>
                                    Device name contains <code>SVR</code>: LAG 256{' '}
                                    <code>1/1/21-1/1/22</code>, LAG 255 <code>1/1/23-1/1/24</code>
                                </li>
                            </ul>
                            <p className="text-muted-foreground text-sm">
                                If the device name contains neither <code>CORE</code> nor <code>SVR</code>,
                                set <code>vsx_isl_ports</code> and <code>vsx_keepalive_ports</code> on both
                                peers instead. Existing LAG 256/255 configurations are validated; missing
                                LAGs are created when Central returns 404.
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    'group',
                                    'site',
                                    'vsx_profile',
                                    'vsx_role',
                                    'vsx_system_mac',
                                ]}
                                optional={['vsx_isl_ports', 'vsx_keepalive_ports']}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Configure Mirror Session" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Device-based task{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">
                                    CONFIGURE_MIRROR_SESSION
                                </code>{' '}
                                that creates or updates a local mirror session on selected switches for
                                Darktrace SPAN. Select devices on the deployment when starting the task; CSV
                                rows identify devices only (no interface columns).
                            </p>
                            <p className="font-medium text-sm">Fallback mode (no mirror columns in CSV)</p>
                            <p className="text-sm">
                                Used when none of the selected devices have any{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">mirror_*</code> columns
                                populated. Only devices whose names contain{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">CORE</code>,{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">FZN-MDF-MGMT</code>, or{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">MDF-MGMT</code> are
                                attached to the task. Session ID is <code>1</code> and the mirror name is{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">
                                    {'{device.name}'}-DARKTRACE-SPAN
                                </code>
                                .
                            </p>
                            <p className="font-medium text-sm">Default SPAN destination ports (fallback mode)</p>
                            <ul className="list-inside list-disc text-sm">
                                <li>
                                    Name contains <code>FZN-MDF-MGMT</code>: <code>1/1/21</code>,{' '}
                                    <code>1/1/22</code>
                                </li>
                                <li>
                                    Name contains <code>MDF-MGMT</code> (and not matched above):{' '}
                                    <code>1/1/16</code>, <code>2/1/9</code>
                                </li>
                                <li>
                                    Name contains <code>CORE</code> (and not matched above):{' '}
                                    <code>1/1/43</code>
                                </li>
                            </ul>
                            <p className="font-medium text-sm">Mirror source VLANs</p>
                            <p className="text-sm">
                                VLANs are built from L2 VLANs returned by Central at the device&apos;s local
                                scope. When <code>group</code> is set on the device, a second lookup runs at
                                the group&apos;s scope ID and the results are merged (duplicates removed,
                                sorted). Include <code>group</code> on mirror targets so group VLAN templates
                                are included in the session.
                            </p>
                            <p className="font-medium text-sm">Explicit mode (mirror columns in CSV)</p>
                            <p className="text-sm">
                                Used when any selected device has a populated{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">mirror_*</code> column.
                                Only devices with at least one mirror column set are attached.{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">mirror_dst_ports</code>{' '}
                                is required per device; other mirror columns override defaults when provided.
                                If <code>mirror_vlans</code> is omitted, VLANs are fetched from Central using
                                the same device-plus-group merge as fallback mode.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                The task posts the mirror session to Central and retries with a patch if the
                                post fails (for example when the session already exists).
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function']}
                                optional={[
                                    'group',
                                    'mirror_dst_ports',
                                    'mirror_session_id',
                                    'mirror_vlans',
                                    'mirror_name',
                                ]}
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
                                            VPNC, MICROBRANCH_AP, ACCESS_SWITCH, SERVICE_PERSONA
                                        </i>
                                        )
                                    </>,
                                ]}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Add VLANs to device groups"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                Device-based task that adds VLAN templates to Central device groups. Each
                                unique <code className="rounded bg-muted px-1 py-0.5 text-sm">group</code>{' '}
                                value in your CSV spawns one sub-task per group. If a group does not exist
                                in Central yet, a prerequisite create-group step is queued automatically.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Alternatively, enter a site prefix on the task card (for example{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">SAC</code>) to
                                target WHSE-{'{prefix}'}-ACCESS, CORE, MGMT, DMZ, and SERVER groups without
                                selecting individual devices.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                When a site prefix is set, you must also choose a{' '}
                                <strong>CX firmware compliance</strong> version from the dropdown. Deployer
                                loads available CX versions from Classic Central and applies the selected
                                version to all five WHSE groups before VLAN templates are pushed.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                For each site-prefix group, deploy order is: create the group in Central (only
                                if missing), set CX firmware compliance, then add VLANs. Compliance runs for
                                existing groups as well as newly created ones.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Device-based deploys (selecting devices by{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">group</code> column)
                                do not use the firmware dropdown and do not set compliance.
                            </p>
                            <ColumnPair
                                required={['group']}
                                optional={['name', 'serial', 'device_function']}
                            />
                        </div>
                    </DocCard>

                    <DocCard title="Relaunch failed critical configurations" defaultOpen>
                        <div className="space-y-4">
                            <p>
                                Composite task{' '}
                                <code className="rounded bg-muted px-1 py-0.5 text-sm">
                                    RELAUNCH_FAILED_CRITICAL_CONFIG
                                </code>{' '}
                                that retries failed LAG, Ethernet, and VLAN interface work and removes local
                                overrides for static route, DNS, and local management profiles. It runs
                                against devices and interface rows already loaded on the deployment—no
                                separate CSV upload.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Ensure your deployment CSV includes the columns required by{' '}
                                <strong>Configure Portchannel/LAG interface</strong>,{' '}
                                <strong>Configure Ethernet Interfaces</strong>, and{' '}
                                <strong>Configure SVI</strong> for the interfaces you expect this task to
                                retry.
                            </p>
                            <ColumnPair
                                required={[
                                    'name',
                                    'serial',
                                    'device_function',
                                    'interface',
                                ]}
                                optional={[
                                    'port_list',
                                    'ip_address',
                                    'port_profile',
                                    'description',
                                    'interface_mode',
                                    'access_vlan',
                                    'native_vlan',
                                    'trunk_vlan_all',
                                    'trunk_vlan_ranges',
                                    'lacp_mode',
                                    'lacp_rate',
                                    'trunk_type',
                                ]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Assign Subscription"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                Device-based licensing task. CSV rows identify which devices to license;
                                subscription tag, license type, and pool selection are chosen on the task
                                card (uniform pool or per-device license modal)—not in the CSV.
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Requires a current licensing inventory sync and available pool seats for
                                the selected tag and license type.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Unassign Subscription"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                Classic Central API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                Device-based licensing task that removes GreenLake subscription assignments
                                from selected devices. CSV rows identify devices by name, serial, and
                                function; the task uses each device&apos;s current subscription from the
                                licensing inventory.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function']}
                                optional={[]}
                            />
                        </div>
                    </DocCard>

                    <DocCard
                        title="Add Devices to GreenLake Inventory"
                        badge={
                            <Badge variant="outline" className="w-fit shrink-0 font-normal">
                                GreenLake API
                            </Badge>
                        }
                        defaultOpen
                    >
                        <div className="space-y-4">
                            <p>
                                Adds selected network devices to the HPE GreenLake workspace inventory
                                via serial number and MAC address. The <code>mac_address</code> CSV
                                column is optional on import; it becomes required when launching this
                                task. If devices are missing a MAC, the task card shows an error and
                                opens a modal to enter and save addresses before deploy.
                            </p>
                            <ColumnPair
                                required={['name', 'serial', 'device_function', 'mac_address']}
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
