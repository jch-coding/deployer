export const LICENSE_TYPE_OPTIONS = [
    'Foundation AP',
    'Advanced AP',
    'Foundation Gateway',
    'Foundation-Switch-Class-1',
    'Advanced-Switch-Class-1',
    'Foundation-Switch-Class-2',
    'Advanced-Switch-Class-2',
    'Foundation-Switch-Class-3',
    'Advanced-Switch-Class-3',
    'Foundation-Switch-Class-4',
    'Advanced-Switch-Class-4',
    'Foundation-Switch-Class-5',
    'Advanced-Switch-Class-5',
] as const;

export type LicenseTypeOption = (typeof LICENSE_TYPE_OPTIONS)[number];

function normalizeTierDescription(tierDescription: string): string {
    return tierDescription
        .toLowerCase()
        .trim()
        .replace(/[_-]/g, ' ')
        .replace(/\s+/g, ' ');
}

function containsAll(haystack: string, needles: string[]): boolean {
    return needles.every((needle) => haystack.includes(needle));
}

function containsAny(haystack: string, needles: string[]): boolean {
    return needles.some((needle) => haystack.includes(needle));
}

function isSwitchTier(normalized: string, tier: 'foundation' | 'advanced', classNumber: number): boolean {
    if (!normalized.includes('switch') && !normalized.includes('class')) {
        return false;
    }

    const hasTier = normalized.includes(tier);
    const classPatterns = [`class ${classNumber}`, `class${classNumber}`];
    let hasClass = classPatterns.some((pattern) => normalized.includes(pattern));

    if (!hasClass && new RegExp(`class[\\s-]*${classNumber}(?!\\d)`).test(normalized)) {
        hasClass = true;
    }

    if (classNumber === 1 && !hasClass && hasTier && normalized.includes('switch')) {
        return !/class[\s-]*[2-5]/.test(normalized);
    }

    return hasTier && hasClass;
}

export function licenseTypeMatchesTierDescription(
    licenseType: LicenseTypeOption,
    tierDescription: string,
): boolean {
    if (tierDescription.trim().toLowerCase() === licenseType.toLowerCase()) {
        return true;
    }

    const normalized = normalizeTierDescription(tierDescription);

    switch (licenseType) {
        case 'Foundation AP':
            return (
                containsAll(normalized, ['foundation', 'ap']) &&
                !containsAny(normalized, ['switch', 'gateway'])
            );
        case 'Advanced AP':
            return (
                containsAll(normalized, ['advanced', 'ap']) &&
                !containsAny(normalized, ['switch', 'gateway'])
            );
        case 'Foundation Gateway':
            return containsAll(normalized, ['foundation', 'gateway']);
        case 'Foundation-Switch-Class-1':
            return isSwitchTier(normalized, 'foundation', 1);
        case 'Advanced-Switch-Class-1':
            return isSwitchTier(normalized, 'advanced', 1);
        case 'Foundation-Switch-Class-2':
            return isSwitchTier(normalized, 'foundation', 2);
        case 'Advanced-Switch-Class-2':
            return isSwitchTier(normalized, 'advanced', 2);
        case 'Foundation-Switch-Class-3':
            return isSwitchTier(normalized, 'foundation', 3);
        case 'Advanced-Switch-Class-3':
            return isSwitchTier(normalized, 'advanced', 3);
        case 'Foundation-Switch-Class-4':
            return isSwitchTier(normalized, 'foundation', 4);
        case 'Advanced-Switch-Class-4':
            return isSwitchTier(normalized, 'advanced', 4);
        case 'Foundation-Switch-Class-5':
            return isSwitchTier(normalized, 'foundation', 5);
        case 'Advanced-Switch-Class-5':
            return isSwitchTier(normalized, 'advanced', 5);
        default:
            return false;
    }
}

export function licenseTypeDeviceCategories(licenseType: LicenseTypeOption): string[] {
    if (licenseType === 'Foundation AP' || licenseType === 'Advanced AP') {
        return ['ap'];
    }

    if (licenseType === 'Foundation Gateway') {
        return ['gateway'];
    }

    return ['switch'];
}

export function filterLicenseTypesByDeviceCategory(
    licenseTypes: readonly LicenseTypeOption[],
    switchesOnly: boolean,
    apsOnly: boolean,
): LicenseTypeOption[] {
    if (!switchesOnly && !apsOnly) {
        return [...licenseTypes];
    }

    return licenseTypes.filter((licenseType) => {
        const categories = licenseTypeDeviceCategories(licenseType);
        if (switchesOnly) {
            return categories.includes('switch');
        }
        if (apsOnly) {
            return categories.includes('ap');
        }

        return true;
    });
}
