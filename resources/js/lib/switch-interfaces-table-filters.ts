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

export function matchesSwitchInterfaceTextFilter(haystack: string, needle: string): boolean {
    const trimmed = needle.trim();
    if (trimmed === '') {
        return true;
    }

    return haystack.toLowerCase().includes(trimmed.toLowerCase());
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
        if (!matchesSwitchInterfaceTextFilter(iface.name, filters.name)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.status, filters.status)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.operStatus, filters.operStatus)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.neighbour, filters.neighbour)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.neighbourSerial, filters.neighbourSerial)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.vlanMode, filters.vlanMode)) {
            return false;
        }
        if (
            !matchesSwitchInterfaceTextFilter(
                formatAllowedVlanIds(iface.allowedVlanIds),
                filters.allowedVlanIds,
            )
        ) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.nativeVlan, filters.nativeVlan)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.poeClass, filters.poeClass)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.neighbourFamily, filters.neighbourFamily)) {
            return false;
        }
        if (
            !matchesSwitchInterfaceTextFilter(iface.neighbourFunction, filters.neighbourFunction)
        ) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.neighbourType, filters.neighbourType)) {
            return false;
        }
        if (!matchesSwitchInterfaceTextFilter(iface.transceiverType, filters.transceiverType)) {
            return false;
        }

        return true;
    });
}
