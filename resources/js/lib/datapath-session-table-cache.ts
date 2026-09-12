import type { DatapathSessionTableParseResult } from '@/lib/datapath-session-table';

export type DatapathSessionTableCacheEntry = {
    table: DatapathSessionTableParseResult;
};

const cache = new Map<string, DatapathSessionTableCacheEntry>();

export function getDatapathSessionTableCacheEntry(
    serial: string,
): DatapathSessionTableCacheEntry | undefined {
    return cache.get(serial.trim());
}

export function hasDatapathSessionTableCacheEntry(serial: string): boolean {
    return cache.has(serial.trim());
}

export function setDatapathSessionTableCacheEntry(
    serial: string,
    entry: DatapathSessionTableCacheEntry,
): void {
    const trimmed = serial.trim();
    if (trimmed === '') {
        return;
    }

    cache.set(trimmed, entry);
}

export function clearDatapathSessionTableCache(): void {
    cache.clear();
}

export function datapathSessionTableCacheSize(): number {
    return cache.size;
}
