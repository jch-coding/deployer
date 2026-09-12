import {
    formatAllowedVlanIds,
    type SwitchInterfaceRow,
} from '@/lib/switch-interfaces-csv';

export type SwitchInterfacesTableFilters = {
    name: string;
    status: string;
    operStatus: string;
    neighbour: string;
    neighbourSerial: string;
    vlanMode: string;
    allowedVlanIds: string;
    nativeVlan: string;
    poeClass: string;
    neighbourFamily: string;
    neighbourFunction: string;
    neighbourType: string;
    transceiverType: string;
};

export type SwitchInterfacesFilterOptions = {
    [K in keyof SwitchInterfacesTableFilters]: string[];
};

export const emptySwitchInterfacesTableFilters: SwitchInterfacesTableFilters = {
    name: '',
    status: '',
    operStatus: '',
    neighbour: '',
    neighbourSerial: '',
    vlanMode: '',
    allowedVlanIds: '',
    nativeVlan: '',
    poeClass: '',
    neighbourFamily: '',
    neighbourFunction: '',
    neighbourType: '',
    transceiverType: '',
};

function uniqueSortedValues(values: string[]): string[] {
    const unique = [...new Set(values.filter((value) => value.trim() !== ''))];
    unique.sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

    return unique;
}

function fieldDisplayValue(
    iface: SwitchInterfaceRow,
    key: keyof SwitchInterfacesTableFilters,
): string {
    if (key === 'allowedVlanIds') {
        return formatAllowedVlanIds(iface.allowedVlanIds);
    }

    return iface[key];
}

export function buildSwitchInterfaceFilterOptions(
    interfaces: SwitchInterfaceRow[],
): SwitchInterfacesFilterOptions {
    const keys = Object.keys(emptySwitchInterfacesTableFilters) as (keyof SwitchInterfacesTableFilters)[];
    const options = {} as SwitchInterfacesFilterOptions;

    for (const key of keys) {
        options[key] = uniqueSortedValues(interfaces.map((iface) => fieldDisplayValue(iface, key)));
    }

    return options;
}

export function matchesSwitchInterfaceExactFilter(haystack: string, selected: string): boolean {
    if (selected.trim() === '') {
        return true;
    }

    return haystack === selected;
}

export function hasActiveSwitchInterfacesTableFilters(
    filters: SwitchInterfacesTableFilters,
): boolean {
    return (Object.keys(filters) as (keyof SwitchInterfacesTableFilters)[]).some(
        (key) => filters[key].trim() !== '',
    );
}

export function filterSwitchInterfaces<T extends SwitchInterfaceRow>(
    interfaces: T[],
    filters: SwitchInterfacesTableFilters,
): T[] {
    return interfaces.filter((iface) => {
        const keys = Object.keys(filters) as (keyof SwitchInterfacesTableFilters)[];

        return keys.every((key) =>
            matchesSwitchInterfaceExactFilter(fieldDisplayValue(iface, key), filters[key]),
        );
    });
}

/** Fields included in the page-level "Search all" substring match. */
export const switchInterfaceSearchAllFields = [
    'neighbourSerial',
    'nativeVlan',
] as const satisfies ReadonlyArray<keyof SwitchInterfaceRow>;

export function matchesSwitchInterfaceSearchAll(
    iface: SwitchInterfaceRow,
    query: string,
): boolean {
    const trimmed = query.trim();
    if (trimmed === '') {
        return true;
    }

    const needle = trimmed.toLowerCase();

    return switchInterfaceSearchAllFields.some((key) =>
        String(iface[key]).toLowerCase().includes(needle),
    );
}

export function filterSwitchInterfacesBySearchAll<T extends SwitchInterfaceRow>(
    interfaces: T[],
    query: string,
): T[] {
    return interfaces.filter((iface) => matchesSwitchInterfaceSearchAll(iface, query));
}
