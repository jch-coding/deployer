import { describe, expect, it } from 'vitest';
import { filterSearchableSelectOptions } from './searchable-select';

describe('filterSearchableSelectOptions', () => {
    const options = [
        { value: 'hq', label: 'HQ Campus' },
        { value: 'branch', label: 'Branch Office' },
        { value: 'none', label: 'None' },
    ];

    it('returns all options for an empty query', () => {
        expect(filterSearchableSelectOptions(options, '')).toEqual(options);
        expect(filterSearchableSelectOptions(options, '   ')).toEqual(options);
    });

    it('filters case-insensitively by label substring', () => {
        expect(filterSearchableSelectOptions(options, 'hq')).toEqual([
            { value: 'hq', label: 'HQ Campus' },
        ]);
        expect(filterSearchableSelectOptions(options, 'OFFICE')).toEqual([
            { value: 'branch', label: 'Branch Office' },
        ]);
        expect(filterSearchableSelectOptions(options, 'n')).toEqual([
            { value: 'branch', label: 'Branch Office' },
            { value: 'none', label: 'None' },
        ]);
    });

    it('returns an empty list when nothing matches', () => {
        expect(filterSearchableSelectOptions(options, 'zzz')).toEqual([]);
    });
});
