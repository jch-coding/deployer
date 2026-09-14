import { describe, expect, it } from 'vitest';
import {
    buildNeighboursFilterOptions,
    emptyNeighboursTableFilters,
    filterNeighbours,
    hasActiveNeighboursTableFilters,
    matchesNeighbourExactFilter,
    type NeighbourRow,
} from './device-neighbours';

function makeRow(overrides: Partial<NeighbourRow> = {}): NeighbourRow {
    return {
        memberSerial: 'SG90KMX08R',
        name: 'DISTR-2-VSX-Primary',
        type: 'Switch',
        serial: 'TW8AK7206R',
        health: 'Good',
        toPort: '1/1/46',
        localPort: '1/1/23',
        siteId: '965498321',
        siteName: 'Site1',
        ...overrides,
    };
}

describe('matchesNeighbourExactFilter', () => {
    it('matches all values when the selected filter is empty', () => {
        expect(matchesNeighbourExactFilter('Switch', '')).toBe(true);
        expect(matchesNeighbourExactFilter('Switch', '   ')).toBe(true);
    });

    it('matches only exact values', () => {
        expect(matchesNeighbourExactFilter('Switch', 'Switch')).toBe(true);
        expect(matchesNeighbourExactFilter('Switch', 'switch')).toBe(false);
        expect(matchesNeighbourExactFilter('Good', 'Bad')).toBe(false);
    });
});

describe('buildNeighboursFilterOptions', () => {
    it('returns distinct sorted options per column', () => {
        const options = buildNeighboursFilterOptions([
            makeRow({ type: 'Switch', health: 'Good', siteName: 'SiteB' }),
            makeRow({
                type: 'AP',
                health: 'Good',
                siteName: 'SiteA',
                serial: 'AP001',
            }),
            makeRow({ type: 'Switch', health: '', siteName: '' }),
        ]);

        expect(options.type).toEqual(['AP', 'Switch']);
        expect(options.health).toEqual(['Good']);
        expect(options.siteName).toEqual(['SiteA', 'SiteB']);
    });
});

describe('filterNeighbours', () => {
    it('returns all neighbours when filters are empty', () => {
        const rows = [makeRow({ name: 'a' }), makeRow({ name: 'b' })];

        expect(filterNeighbours(rows, emptyNeighboursTableFilters)).toEqual(rows);
    });

    it('filters by exact column matches', () => {
        const rows = [
            makeRow({ name: 'a', type: 'Switch', health: 'Good' }),
            makeRow({ name: 'b', type: 'AP', health: 'Good' }),
            makeRow({ name: 'c', type: 'Switch', health: 'Poor' }),
        ];

        expect(
            filterNeighbours(rows, {
                ...emptyNeighboursTableFilters,
                type: 'Switch',
            }).map((row) => row.name),
        ).toEqual(['a', 'c']);

        expect(
            filterNeighbours(rows, {
                ...emptyNeighboursTableFilters,
                type: 'Switch',
                health: 'Good',
            }).map((row) => row.name),
        ).toEqual(['a']);
    });
});

describe('hasActiveNeighboursTableFilters', () => {
    it('detects when any filter is set', () => {
        expect(hasActiveNeighboursTableFilters(emptyNeighboursTableFilters)).toBe(
            false,
        );
        expect(
            hasActiveNeighboursTableFilters({
                ...emptyNeighboursTableFilters,
                type: 'Switch',
            }),
        ).toBe(true);
    });
});
