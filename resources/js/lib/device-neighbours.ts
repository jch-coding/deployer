export type NeighbourRow = {
    memberSerial: string;
    name: string;
    type: string;
    serial: string;
    health: string;
    toPort: string;
    localPort: string;
    siteId: string;
    siteName: string;
};

export type NeighboursTableFilters = {
    memberSerial: string;
    name: string;
    type: string;
    serial: string;
    health: string;
    toPort: string;
    localPort: string;
    siteId: string;
    siteName: string;
};

export type NeighboursFilterOptions = {
    [K in keyof NeighboursTableFilters]: string[];
};

export const emptyNeighboursTableFilters: NeighboursTableFilters = {
    memberSerial: '',
    name: '',
    type: '',
    serial: '',
    health: '',
    toPort: '',
    localPort: '',
    siteId: '',
    siteName: '',
};

function uniqueSortedValues(values: string[]): string[] {
    const unique = [...new Set(values.filter((value) => value.trim() !== ''))];
    unique.sort((a, b) =>
        a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }),
    );

    return unique;
}

export function buildNeighboursFilterOptions(
    neighbours: NeighbourRow[],
): NeighboursFilterOptions {
    const keys = Object.keys(
        emptyNeighboursTableFilters,
    ) as (keyof NeighboursTableFilters)[];
    const options = {} as NeighboursFilterOptions;

    for (const key of keys) {
        options[key] = uniqueSortedValues(neighbours.map((row) => row[key]));
    }

    return options;
}

export function matchesNeighbourExactFilter(
    haystack: string,
    selected: string,
): boolean {
    if (selected.trim() === '') {
        return true;
    }

    return haystack === selected;
}

export function hasActiveNeighboursTableFilters(
    filters: NeighboursTableFilters,
): boolean {
    return (Object.keys(filters) as (keyof NeighboursTableFilters)[]).some(
        (key) => filters[key].trim() !== '',
    );
}

export function filterNeighbours(
    neighbours: NeighbourRow[],
    filters: NeighboursTableFilters,
): NeighbourRow[] {
    return neighbours.filter((row) => {
        const keys = Object.keys(filters) as (keyof NeighboursTableFilters)[];

        return keys.every((key) =>
            matchesNeighbourExactFilter(row[key], filters[key]),
        );
    });
}
