const SAMPLE_DEVICE_HEADERS = [
    'name',
    'serial',
    'device_function',
    'description',
    'interface',
    'port_list',
    'ip_address',
    'sku',
    'site',
    'group',
    'port_profile',
    'interface_mode',
    'access_vlan',
    'native_vlan',
    'trunk_vlan_all',
    'trunk_vlan_ranges',
    'trunk_type',
] as const;

type SampleDeviceRow = Partial<
    Record<(typeof SAMPLE_DEVICE_HEADERS)[number], string>
>;

const SAMPLE_DEVICE_ROWS: SampleDeviceRow[] = [
    {
        name: 'Example Campus AP',
        serial: 'SN0000000001',
        device_function: 'CAMPUS_AP',
        site: 'Example Campus',
    },
    {
        name: 'Example Access Switch 1',
        serial: 'SN0000000002',
        device_function: 'ACCESS_SWITCH',
        description: 'data port VLAN 10',
        interface: '1/1/1-1/1/48',
        site: 'Building-A',
        port_profile: 'User-Access',
        interface_mode: 'ACCESS',
        access_vlan: '10',
    },
    {
        name: 'Example Access Switch 1',
        serial: 'SN0000000002',
        device_function: 'ACCESS_SWITCH',
        description: 'to Core Switch',
        interface: '1/1/51-1/1/52',
        site: 'Building-A',
        interface_mode: 'TRUNK',
        native_vlan: '10',
        trunk_vlan_ranges: '8&10-20',
    },
    {
        name: 'Example Access Switch 2',
        serial: 'SN0000000003',
        device_function: 'ACCESS_SWITCH',
        interface: '1/1/1&1/1/6-1/1/48',
        site: 'Building-A',
        interface_mode: 'ACCESS',
        access_vlan: '20',
    },
    {
        name: 'Example Access Switch 3',
        serial: 'SN0000000004',
        device_function: 'ACCESS_SWITCH',
        interface: '5',
        port_list: '1/1/14-1/1/15',
        site: 'Building-A',
        interface_mode: 'TRUNK',
        native_vlan: '10',
        trunk_vlan_all: 'true',
        trunk_type: 'LACP',
    },
    {
        name: 'Example L3 Switch',
        serial: 'SN0000000005',
        device_function: 'ACCESS_SWITCH',
        interface: '11',
        ip_address: '192.168.11.1/24',
        site: 'Core-Site',
    },
    {
        name: 'Example Stack Conductor',
        serial: 'SN0000000006',
        device_function: 'ACCESS_SWITCH',
        interface: '1/1/49',
        sku: 'JL679A',
        site: 'Data Center',
    },
    {
        name: 'Example Stack Conductor',
        serial: 'SN0000000007',
        device_function: 'ACCESS_SWITCH',
        description: 'stack member switch',
        site: 'Core-Site',
    },
    {
        name: 'Example Branch Switch',
        serial: 'SN0000000008',
        device_function: 'ACCESS_SWITCH',
        site: 'Branch-HQ',
    },
    {
        name: 'Example New Switch',
        serial: 'SN0000000009',
        device_function: 'ACCESS_SWITCH',
        group: 'Onboarding-Pool',
    },
    {
        name: 'Example Core Switch',
        serial: 'SN0000000010',
        device_function: 'CORE_SWITCH',
    },
];

export function buildSampleDeviceCsv(): string {
    const lines = [
        SAMPLE_DEVICE_HEADERS.join(','),
        ...SAMPLE_DEVICE_ROWS.map((row) =>
            SAMPLE_DEVICE_HEADERS.map((h) => row[h] ?? '').join(','),
        ),
    ];
    return `\uFEFF${lines.join('\n')}`;
}

export function downloadSampleDeviceCsv(): void {
    const blob = new Blob([buildSampleDeviceCsv()], {
        type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'sample-device-configuration.csv';
    anchor.rel = 'noopener';
    anchor.click();
    URL.revokeObjectURL(url);
}
