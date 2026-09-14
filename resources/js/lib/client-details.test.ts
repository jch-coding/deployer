import { describe, expect, it } from 'vitest';
import {
    buildClientDetailsFilterOptions,
    emptyClientDetailsTableFilters,
    filterClientDetailsRows,
    hasActiveClientDetailsTableFilters,
    matchesClientDetailsSearch,
    splitClientTags,
    type ClientDetailsRow,
} from './client-details';

const rows: ClientDetailsRow[] = [
    {
        macAddress: 'aa:bb:cc:dd:ee:01',
        interface: '1/1/1',
        ipv4: '10.0.0.1',
        clientVendor: 'Android',
        port: '1/1/1',
        vlanId: '100',
        clientOperatingSystem: 'Android',
        clientFunction: 'Mobile',
        role: 'wireless',
        clientTags: 'tag1,tag2',
        clientManufacturer: 'Samsung',
        clientCategory: 'Smart Device',
        authenticationType: 'WPA2',
    },
    {
        macAddress: 'aa:bb:cc:dd:ee:02',
        interface: '1/1/2',
        ipv4: '10.0.0.2',
        clientVendor: 'Apple',
        port: '1/1/2',
        vlanId: '200',
        clientOperatingSystem: 'iOS',
        clientFunction: 'Mobile',
        role: 'employee',
        clientTags: 'vip',
        clientManufacturer: 'Apple',
        clientCategory: 'Phone',
        authenticationType: '802.1X',
    },
];

describe('filterClientDetailsRows', () => {
    it('filters by exact column values', () => {
        const filtered = filterClientDetailsRows(
            rows,
            { ...emptyClientDetailsTableFilters, role: 'employee' },
            '',
        );

        expect(filtered).toHaveLength(1);
        expect(filtered[0]?.macAddress).toBe('aa:bb:cc:dd:ee:02');
    });

    it('searches role or clientTags', () => {
        expect(matchesClientDetailsSearch(rows[0]!, 'wire')).toBe(true);
        expect(matchesClientDetailsSearch(rows[0]!, 'tag2')).toBe(true);
        expect(matchesClientDetailsSearch(rows[0]!, 'missing')).toBe(false);

        const filtered = filterClientDetailsRows(
            rows,
            emptyClientDetailsTableFilters,
            'vip',
        );
        expect(filtered).toHaveLength(1);
        expect(filtered[0]?.clientTags).toBe('vip');
    });
});

describe('buildClientDetailsFilterOptions', () => {
    it('builds unique sorted options', () => {
        const options = buildClientDetailsFilterOptions(rows);
        expect(options.role).toEqual(['employee', 'wireless']);
        expect(options.clientVendor).toEqual(['Android', 'Apple']);
    });
});

describe('helpers', () => {
    it('detects active filters and splits tags', () => {
        expect(
            hasActiveClientDetailsTableFilters(emptyClientDetailsTableFilters),
        ).toBe(false);
        expect(
            hasActiveClientDetailsTableFilters({
                ...emptyClientDetailsTableFilters,
                vlanId: '100',
            }),
        ).toBe(true);
        expect(splitClientTags('tag1, tag2,,tag3')).toEqual([
            'tag1',
            'tag2',
            'tag3',
        ]);
    });
});
