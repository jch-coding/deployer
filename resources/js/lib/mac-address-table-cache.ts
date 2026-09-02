import type { MacAddressTableParseResult } from '@/lib/mac-address-table';

export type MacAddressTableCacheEntry = {
    deviceName: string;
    table: MacAddressTableParseResult;
};

const cache = new Map<string, MacAddressTableCacheEntry>();

export function getMacAddressTableCacheEntry(
    serial: string,
): MacAddressTableCacheEntry | undefined {
    return cache.get(serial.trim());
}

export function hasMacAddressTableCacheEntry(serial: string): boolean {
    return cache.has(serial.trim());
}

export function setMacAddressTableCacheEntry(
    serial: string,
    entry: MacAddressTableCacheEntry,
): void {
    const trimmed = serial.trim();
    if (trimmed === '') {
        return;
    }

    cache.set(trimmed, entry);
}

export function clearMacAddressTableCache(): void {
    cache.clear();
}

export function macAddressTableCacheSize(): number {
    return cache.size;
}
