import { subscriptionTagKeys } from '@/lib/subscription-tags';

export type LicensingTableFilters = {
    serial_number: string;
    device_name: string;
    subscription_key: string;
    subscription_tags: string;
    model: string;
};

export const emptyLicensingTableFilters: LicensingTableFilters = {
    serial_number: '',
    device_name: '',
    subscription_key: '',
    subscription_tags: '',
    model: '',
};

export function matchesLicensingTextFilter(haystack: string, needle: string): boolean {
    const trimmed = needle.trim();
    if (trimmed === '') {
        return true;
    }

    return haystack.toLowerCase().includes(trimmed.toLowerCase());
}

export function matchesLicensingTagFilter(tagKeys: string[], rawTags: string): boolean {
    const trimmed = rawTags.trim();
    if (trimmed === '') {
        return true;
    }

    const tokens = trimmed
        .split(',')
        .map((token) => token.trim())
        .filter((token) => token !== '');

    if (tokens.length === 0) {
        return true;
    }

    return tokens.every((token) => {
        const tokenLower = token.toLowerCase();

        return tagKeys.some((tagKey) => tagKey.toLowerCase().includes(tokenLower));
    });
}

export function hasActiveLicensingTableFilters(filters: LicensingTableFilters): boolean {
    return Object.values(filters).some((value) => value.trim() !== '');
}

type LicensingDeviceLike = {
    serial: string;
    name: string;
    model: string;
    subscription_key: string;
    tags: string[] | Record<string, string>;
};

export function filterLicensingDevices<T extends LicensingDeviceLike>(
    devices: T[],
    filters: LicensingTableFilters,
): T[] {
    return devices.filter((device) => {
        if (!matchesLicensingTextFilter(device.serial, filters.serial_number)) {
            return false;
        }

        if (!matchesLicensingTextFilter(device.name, filters.device_name)) {
            return false;
        }

        if (!matchesLicensingTextFilter(device.subscription_key, filters.subscription_key)) {
            return false;
        }

        if (!matchesLicensingTextFilter(device.model, filters.model)) {
            return false;
        }

        if (!matchesLicensingTagFilter(subscriptionTagKeys(device.tags), filters.subscription_tags)) {
            return false;
        }

        return true;
    });
}
