import { describe, expect, it } from 'vitest';
import type { SwitchInterfaceRow } from '@/lib/switch-interfaces-csv';
import {
    filterSwitchInterfacesBySearchAll,
    matchesSwitchInterfaceSearchAll,
} from './switch-interfaces-table-filters';

function makeRow(overrides: Partial<SwitchInterfaceRow> = {}): SwitchInterfaceRow {
    return {
        name: '1/1/1',
        status: 'Connected',
        operStatus: 'Up',
        neighbour: 'AP-1',
        neighbourSerial: 'APSN1',
        vlanMode: 'ACCESS',
        allowedVlanIds: [1],
        nativeVlan: '10',
        poeClass: '',
        neighbourFamily: '',
        neighbourFunction: '',
        neighbourType: '',
        transceiverType: '',
        ...overrides,
    };
}

describe('matchesSwitchInterfaceSearchAll', () => {
    it('matches all rows when the query is empty or whitespace', () => {
        const row = makeRow();

        expect(matchesSwitchInterfaceSearchAll(row, '')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, '   ')).toBe(true);
    });

    it('matches Neighbour Serial by case-insensitive substring', () => {
        const row = makeRow({ neighbourSerial: 'APSN12345', nativeVlan: '99' });

        expect(matchesSwitchInterfaceSearchAll(row, 'psn12')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, 'APSN12345')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, 'zzzz')).toBe(false);
    });

    it('matches Native VLAN by case-insensitive substring', () => {
        const row = makeRow({ neighbourSerial: '', nativeVlan: '100' });

        expect(matchesSwitchInterfaceSearchAll(row, '10')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, '100')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, '200')).toBe(false);
    });

    it('matches when either Neighbour Serial or Native VLAN hits', () => {
        const row = makeRow({ neighbourSerial: 'APSN1', nativeVlan: '10' });

        expect(matchesSwitchInterfaceSearchAll(row, 'APSN')).toBe(true);
        expect(matchesSwitchInterfaceSearchAll(row, '10')).toBe(true);
    });

    it('does not match other interface fields', () => {
        const row = makeRow({
            name: '1/1/7',
            neighbour: 'UniqueNeighbour',
            neighbourSerial: 'APSN1',
            nativeVlan: '10',
        });

        expect(matchesSwitchInterfaceSearchAll(row, 'UniqueNeighbour')).toBe(false);
        expect(matchesSwitchInterfaceSearchAll(row, '1/1/7')).toBe(false);
    });
});

describe('filterSwitchInterfacesBySearchAll', () => {
    it('returns all interfaces for an empty query', () => {
        const rows = [makeRow({ name: 'a' }), makeRow({ name: 'b' })];

        expect(filterSwitchInterfacesBySearchAll(rows, '')).toEqual(rows);
    });

    it('filters to matching Neighbour Serial or Native VLAN rows', () => {
        const rows = [
            makeRow({ name: 'a', neighbourSerial: 'APSN1', nativeVlan: '10' }),
            makeRow({ name: 'b', neighbourSerial: 'APSN2', nativeVlan: '20' }),
            makeRow({ name: 'c', neighbourSerial: '', nativeVlan: '10' }),
        ];

        expect(filterSwitchInterfacesBySearchAll(rows, 'APSN1').map((r) => r.name)).toEqual([
            'a',
        ]);
        expect(filterSwitchInterfacesBySearchAll(rows, '10').map((r) => r.name)).toEqual([
            'a',
            'c',
        ]);
        expect(filterSwitchInterfacesBySearchAll(rows, 'nope')).toEqual([]);
    });
});
