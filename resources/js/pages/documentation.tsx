import Layout from '@/layouts/app-layout'
import { Head } from '@inertiajs/react'
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Download } from 'lucide-react';
import { downloadSampleDeviceCsv } from '@/lib/sample-device-csv';

export default function documentation({}) {
    return (
        <Layout>
            <Head title="documentation" />
            <div className="max-w-3/4 mx-auto space-y-4">
                <Card>
                    <CardHeader className="font-bold">
                        CSV required headers
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p>
                            There are three pieces of information that are required
                            for all devices: name, serial and device_function
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
                            Example rows illustrate common column combinations. Replace
                            names, serials, and values with data that matches your
                            Aruba Central configuration.
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">Name Device</CardHeader>
                    <CardContent>
                        <p>Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Configure Ethernet Interfaces
                    </CardHeader>
                    <CardContent>
                        <p>
                            The interface column can be a single interface (in the
                            form of x/x/x) or a range of interfaces or a set of
                            interface ranges separated by an & symbol
                        </p>
                        <p className="mt-2">
                            <i>ex: 1/1/1 or 1/1/1-1/1/48 or 1/1/1&1/1/6-1/1/48</i>
                        </p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>interface</li>
                        </ul>
                        <p className="mt-4">Optional Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>port_profile</li>
                            <li>interface_mode</li>
                            <li>access_vlan</li>
                            <li>native_vlan</li>
                            <li>trunk_vlan_all</li>
                            <li>trunk_vlan_ranges</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Configure Portchannel/LAG Interface
                    </CardHeader>
                    <CardContent>
                        <p>Configuring LAG interfaces only depends on the port_list column for defining physical link members. The individual member interfaces do NOT require an individual row in the CSV file.</p>
                        <p>The interface column should be a single number that will be the LAG ID that will be configured</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>interface</li>
                            <li>port_list (<i>ex: 1/1/1-1/1/3 or 1/1/51&2/1/51</i>)</li>
                        </ul>
                        <p className="mt-4">Optional Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>port_profile</li>
                            <li>interface_mode</li>
                            <li>access_vlan</li>
                            <li>native_vlan</li>
                            <li>trunk_vlan_all</li>
                            <li>trunk_vlan_ranges</li>
                            <li>trunk_type (<i>default</i> LACP, TRUNK, DT_TRUNK, MULTI_CHASSIS, MULTI_CHASSIS_STATIC)</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Configure SVI
                    </CardHeader>
                    <CardContent>
                        <p>The interface column should be a single number that corresponds to the VLAN for which the SVI will be configured</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>interface (<i>ex: 11</i>)</li>
                            <li>ip_address (<i>ex: 192.168.1.1/24</i>)</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card >
                    <CardHeader className="font-bold">
                        Configure VSF Profile
                    </CardHeader>
                    <CardContent>
                        <p>The sku column should be included for the conductor switch ONLY. The VSF profile name will be the name of the conductor plus -STACK appended. Note that this creates an auto-stacking VSF profile only.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>sku</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Associate Devices to Site
                    </CardHeader>
                    <CardContent>
                        <p>The site column should be included for all devices that need to be associated with a site. The site name should be in the site column and must match the site name configured in Central.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>site</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Associate Devices to Site and Name
                    </CardHeader>
                    <CardContent>
                        <p>The site and name columns should be included for all devices that need to be associated with a site and a name. The site name should be in the site column and must match the site name configured in Central. The name column should be the name of the device that will be configured in the device name column.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>site</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Preprovision Devices to Group
                    </CardHeader>
                    <CardContent>
                        <p>The group column should be included for all devices that need to be preprovisioned to a group. The group name should be in the group column and must match the group name configured in Central.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>group</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Move Devices to Device Group
                    </CardHeader>
                    <CardContent>
                        <p>The group column should be included for all devices that need to be moved to a group. The group name should be in the group column and must match the group name configured in Central.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function</li>
                            <li>group</li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="font-bold">
                        Assign Device Function to Devices
                    </CardHeader>
                    <CardContent>
                        <p>The device_function column should be included for all devices that need to be assigned a device function. The device function name should be in the device_function column and must match the device function name configured in Central.</p>
                        <p className="mt-4">Required Columns</p>
                        <ul className="list-inside list-disc mt-2">
                            <li>name</li>
                            <li>serial</li>
                            <li>device_function (<i>CAMPUS_AP, CORE_SWITCH, AGG_SWITCH, BRANCH_GW, MOBILITY_GW, VPNC, MICROBRANCH_AP, ACCESS_SWITCH</i>)</li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </Layout>
    );
}
