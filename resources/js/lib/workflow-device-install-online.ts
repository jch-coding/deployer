export type InstallOnlineBadgeKey =
    | 'installed_online'
    | 'installed_offline'
    | 'not_installed_online'
    | 'not_installed_offline';

export const INSTALL_ONLINE_BADGE_OPTIONS: {
    value: InstallOnlineBadgeKey;
    label: string;
}[] = [
    { value: 'installed_online', label: 'Installed + Online' },
    { value: 'installed_offline', label: 'Installed + Offline' },
    { value: 'not_installed_online', label: 'Not installed + Online' },
    { value: 'not_installed_offline', label: 'Not installed + Offline' },
];

export function installOnlineBadgeKey(
    isInstalled: boolean | null | undefined,
    isOnline: boolean | null | undefined,
): InstallOnlineBadgeKey {
    const installed = isInstalled === true;
    const online = isOnline === true;

    if (installed && online) {
        return 'installed_online';
    }
    if (installed) {
        return 'installed_offline';
    }
    if (online) {
        return 'not_installed_online';
    }

    return 'not_installed_offline';
}

export function installOnlineBadgeLabel(
    key: InstallOnlineBadgeKey,
): string {
    return (
        INSTALL_ONLINE_BADGE_OPTIONS.find((option) => option.value === key)
            ?.label ?? key
    );
}

export function installOnlineBadgeClass(key: InstallOnlineBadgeKey): string {
    switch (key) {
        case 'installed_online':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'installed_offline':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'not_installed_online':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'not_installed_offline':
            return 'bg-red-100 text-red-800 border-red-200';
        default:
            return '';
    }
}
