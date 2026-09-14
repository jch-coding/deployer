export type ClientDetailsRow = {
    macAddress: string;
    interface: string;
    ipv4: string;
    clientVendor: string;
    port: string;
    vlanId: string;
    clientOperatingSystem: string;
    clientFunction: string;
    role: string;
    clientTags: string;
    clientManufacturer: string;
    clientCategory: string;
    authenticationType: string;
};

export type ClientDetailsError = {
    interface: string;
    message: string;
};

export type ClientDetailsTableFilters = {
    ipv4: string;
    clientVendor: string;
    port: string;
    vlanId: string;
    clientOperatingSystem: string;
    clientFunction: string;
    role: string;
    clientTags: string;
    clientManufacturer: string;
    clientCategory: string;
    authenticationType: string;
};

export type ClientDetailsFilterOptions = {
    [K in keyof ClientDetailsTableFilters]: string[];
};

export const emptyClientDetailsTableFilters: ClientDetailsTableFilters = {
    ipv4: '',
    clientVendor: '',
    port: '',
    vlanId: '',
    clientOperatingSystem: '',
    clientFunction: '',
    role: '',
    clientTags: '',
    clientManufacturer: '',
    clientCategory: '',
    authenticationType: '',
};

function uniqueSortedValues(values: string[]): string[] {
    const unique = [...new Set(values.filter((value) => value.trim() !== ''))];
    unique.sort((a, b) =>
        a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }),
    );

    return unique;
}

export function buildClientDetailsFilterOptions(
    rows: ClientDetailsRow[],
): ClientDetailsFilterOptions {
    const keys = Object.keys(
        emptyClientDetailsTableFilters,
    ) as (keyof ClientDetailsTableFilters)[];
    const options = {} as ClientDetailsFilterOptions;

    for (const key of keys) {
        options[key] = uniqueSortedValues(rows.map((row) => row[key]));
    }

    return options;
}

export function matchesClientDetailsExactFilter(
    haystack: string,
    selected: string,
): boolean {
    if (selected.trim() === '') {
        return true;
    }

    return haystack === selected;
}

export function hasActiveClientDetailsTableFilters(
    filters: ClientDetailsTableFilters,
): boolean {
    return (Object.keys(filters) as (keyof ClientDetailsTableFilters)[]).some(
        (key) => filters[key].trim() !== '',
    );
}

export function matchesClientDetailsSearch(
    row: ClientDetailsRow,
    query: string,
): boolean {
    const trimmed = query.trim().toLowerCase();
    if (trimmed === '') {
        return true;
    }

    return (
        row.role.toLowerCase().includes(trimmed) ||
        row.clientTags.toLowerCase().includes(trimmed)
    );
}

export function filterClientDetailsRows(
    rows: ClientDetailsRow[],
    filters: ClientDetailsTableFilters,
    searchQuery: string,
): ClientDetailsRow[] {
    return rows.filter((row) => {
        if (!matchesClientDetailsSearch(row, searchQuery)) {
            return false;
        }

        const keys = Object.keys(filters) as (keyof ClientDetailsTableFilters)[];

        return keys.every((key) =>
            matchesClientDetailsExactFilter(row[key], filters[key]),
        );
    });
}

export function splitClientTags(clientTags: string): string[] {
    return clientTags
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '');
}
