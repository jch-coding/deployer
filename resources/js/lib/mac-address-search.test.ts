import { afterEach, describe, expect, it, vi } from 'vitest';
import { matchesAnyTargetMac, parseMacAddressLines } from './mac-address-table';
import {
    clearMacAddressTableCache,
    getMacAddressTableCacheEntry,
    hasMacAddressTableCacheEntry,
    macAddressTableCacheSize,
    setMacAddressTableCacheEntry,
} from './mac-address-table-cache';
import {
    getCachedOrFetchMacAddressTable,
    MAC_ADDRESS_SEARCH_BATCH_SIZE,
    runMacAddressSearch,
} from './mac-address-search';

const SAMPLE_TABLE = `MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
88:22:5b:7d:ff:00    1        dynamic                   1/1/52     
e8:1c:a5:72:ad:c0    1        dynamic                   1/1/53  `;

const SAMPLE_TABLE_B = `MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
aa:bb:cc:dd:ee:ff    10       dynamic                   1/1/1      `;

afterEach(() => {
    clearMacAddressTableCache();
});

describe('parseMacAddressLines', () => {
    it('parses MACs in multiple formats and dedupes', () => {
        const result = parseMacAddressLines(
            '88:22:5b:7d:ff:00\n88-22-5b-7d-ff-00, 8822.5b7d.ff00',
        );

        expect(result.macs).toEqual(['88:22:5b:7d:ff:00']);
        expect(result.invalidLines).toEqual([]);
    });

    it('returns invalid lines separately', () => {
        const result = parseMacAddressLines('not-a-mac\n88:22:5b:7d:ff:00');

        expect(result.macs).toEqual(['88:22:5b:7d:ff:00']);
        expect(result.invalidLines).toEqual(['not-a-mac']);
    });
});

describe('matchesAnyTargetMac', () => {
    const row = {
        mac: '88:22:5b:7d:ff:00',
        vlan: '1',
        type: 'dynamic',
        port: '1/1/52',
    };

    it('matches target MACs regardless of separator format', () => {
        expect(matchesAnyTargetMac(row, ['88-22-5b-7d-ff-00'])).toBe(true);
        expect(matchesAnyTargetMac(row, ['88225B7DFF00'])).toBe(true);
        expect(matchesAnyTargetMac(row, ['e8:1c:a5:72:ad:c0'])).toBe(false);
    });
});

describe('mac-address-table-cache', () => {
    it('stores and retrieves entries by serial', () => {
        setMacAddressTableCacheEntry('SN1', {
            deviceName: 'Switch-A',
            table: {
                rows: [
                    {
                        mac: '88:22:5b:7d:ff:00',
                        vlan: '1',
                        type: 'dynamic',
                        port: '1/1/52',
                    },
                ],
                ageTime: null,
                count: 1,
                raw: SAMPLE_TABLE,
            },
        });

        expect(hasMacAddressTableCacheEntry('SN1')).toBe(true);
        expect(getMacAddressTableCacheEntry('SN1')?.deviceName).toBe(
            'Switch-A',
        );
        expect(macAddressTableCacheSize()).toBe(1);

        clearMacAddressTableCache();
        expect(macAddressTableCacheSize()).toBe(0);
    });
});

describe('getCachedOrFetchMacAddressTable', () => {
    it('returns cached entry without fetching', async () => {
        const fetchMacAddressTableOutput = vi.fn().mockResolvedValue(SAMPLE_TABLE);

        setMacAddressTableCacheEntry('SN1', {
            deviceName: 'Switch-A',
            table: {
                rows: [
                    {
                        mac: '88:22:5b:7d:ff:00',
                        vlan: '1',
                        type: 'dynamic',
                        port: '1/1/52',
                    },
                ],
                ageTime: null,
                count: 1,
                raw: SAMPLE_TABLE,
            },
        });

        const entry = await getCachedOrFetchMacAddressTable('SN1', {
            fetchMacAddressTableOutput,
        });

        expect(fetchMacAddressTableOutput).not.toHaveBeenCalled();
        expect(entry.deviceName).toBe('Switch-A');
    });

    it('force refresh bypasses cache and overwrites', async () => {
        const fetchMacAddressTableOutput = vi
            .fn()
            .mockResolvedValue(SAMPLE_TABLE_B);

        setMacAddressTableCacheEntry('SN1', {
            deviceName: 'Switch-A',
            table: {
                rows: [
                    {
                        mac: '88:22:5b:7d:ff:00',
                        vlan: '1',
                        type: 'dynamic',
                        port: '1/1/52',
                    },
                ],
                ageTime: null,
                count: 1,
                raw: SAMPLE_TABLE,
            },
        });

        const entry = await getCachedOrFetchMacAddressTable('SN1', {
            force: true,
            deviceName: 'Switch-A',
            fetchMacAddressTableOutput,
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(1);
        expect(entry.table.rows[0]?.mac).toBe('aa:bb:cc:dd:ee:ff');
        expect(getMacAddressTableCacheEntry('SN1')?.table.rows[0]?.mac).toBe(
            'aa:bb:cc:dd:ee:ff',
        );
    });
});

describe('runMacAddressSearch', () => {
    it('aggregates matches and records per-switch errors', async () => {
        const fetchMacAddressTableOutput = vi
            .fn()
            .mockResolvedValueOnce(SAMPLE_TABLE)
            .mockRejectedValueOnce(new Error('Switch offline'));

        const progress: string[] = [];
        const result = await runMacAddressSearch({
            switches: [
                { serial: 'SN1', deviceName: 'Switch-A' },
                { serial: 'SN2', deviceName: 'Switch-B' },
            ],
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
            onProgress: (value) => {
                progress.push(
                    `${value.current}/${value.total}:${value.fetchCount}`,
                );
            },
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(2);
        expect(progress).toEqual(['1/1:2']);
        expect(result.matches).toEqual([
            {
                serial: 'SN1',
                deviceName: 'Switch-A',
                mac: '88:22:5b:7d:ff:00',
                vlan: '1',
                type: 'dynamic',
                port: '1/1/52',
            },
        ]);
        expect(result.errors).toEqual([
            {
                serial: 'SN2',
                deviceName: 'Switch-B',
                error: 'Switch offline',
            },
        ]);
        expect(hasMacAddressTableCacheEntry('SN1')).toBe(true);
        expect(hasMacAddressTableCacheEntry('SN2')).toBe(false);
    });

    it('skips fetch for cached serials and fetches newly added ones', async () => {
        const fetchMacAddressTableOutput = vi
            .fn()
            .mockResolvedValueOnce(SAMPLE_TABLE)
            .mockResolvedValueOnce(SAMPLE_TABLE_B);

        await runMacAddressSearch({
            switches: [{ serial: 'SN1', deviceName: 'Switch-A' }],
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
        });

        const second = await runMacAddressSearch({
            switches: [
                { serial: 'SN1', deviceName: 'Switch-A' },
                { serial: 'SN2', deviceName: 'Switch-B' },
            ],
            targetMacs: ['aa:bb:cc:dd:ee:ff'],
            fetchMacAddressTableOutput,
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(2);
        expect(fetchMacAddressTableOutput).toHaveBeenNthCalledWith(1, 'SN1');
        expect(fetchMacAddressTableOutput).toHaveBeenNthCalledWith(2, 'SN2');
        expect(second.matches).toEqual([
            {
                serial: 'SN2',
                deviceName: 'Switch-B',
                mac: 'aa:bb:cc:dd:ee:ff',
                vlan: '10',
                type: 'dynamic',
                port: '1/1/1',
            },
        ]);
    });

    it('retries failed serials on a later run', async () => {
        const fetchMacAddressTableOutput = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(SAMPLE_TABLE);

        const first = await runMacAddressSearch({
            switches: [{ serial: 'SN1', deviceName: 'Switch-A' }],
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
        });

        expect(first.errors).toHaveLength(1);
        expect(hasMacAddressTableCacheEntry('SN1')).toBe(false);

        const second = await runMacAddressSearch({
            switches: [{ serial: 'SN1', deviceName: 'Switch-A' }],
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
        });

        expect(second.errors).toEqual([]);
        expect(second.matches).toHaveLength(1);
        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(2);
    });

    it('processes more than batch size in sequential batches and keeps partial successes', async () => {
        const switchCount = MAC_ADDRESS_SEARCH_BATCH_SIZE + 3;
        const switches = Array.from({ length: switchCount }, (_, index) => ({
            serial: `SN${index + 1}`,
            deviceName: `Switch-${index + 1}`,
        }));

        const fetchMacAddressTableOutput = vi.fn(
            async (serial: string): Promise<string> => {
                if (serial === 'SN2') {
                    throw new Error('Switch offline');
                }

                return SAMPLE_TABLE;
            },
        );

        const batchProgress: number[] = [];
        const result = await runMacAddressSearch({
            switches,
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
            batchSize: MAC_ADDRESS_SEARCH_BATCH_SIZE,
            onProgress: (value) => {
                batchProgress.push(value.current);
            },
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(switchCount);
        expect(batchProgress).toEqual([1, 2]);
        expect(result.errors).toEqual([
            {
                serial: 'SN2',
                deviceName: 'Switch-2',
                error: 'Switch offline',
            },
        ]);
        expect(result.matches).toHaveLength(switchCount - 1);
        expect(hasMacAddressTableCacheEntry('SN2')).toBe(false);
        expect(hasMacAddressTableCacheEntry('SN1')).toBe(true);
    });

    it('recomputes matches from cache when target MACs change without refetching', async () => {
        const fetchMacAddressTableOutput = vi
            .fn()
            .mockResolvedValue(SAMPLE_TABLE);

        await runMacAddressSearch({
            switches: [{ serial: 'SN1', deviceName: 'Switch-A' }],
            targetMacs: ['88:22:5b:7d:ff:00'],
            fetchMacAddressTableOutput,
        });

        const second = await runMacAddressSearch({
            switches: [{ serial: 'SN1', deviceName: 'Switch-A' }],
            targetMacs: ['e8:1c:a5:72:ad:c0'],
            fetchMacAddressTableOutput,
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(1);
        expect(second.matches).toEqual([
            {
                serial: 'SN1',
                deviceName: 'Switch-A',
                mac: 'e8:1c:a5:72:ad:c0',
                vlan: '1',
                type: 'dynamic',
                port: '1/1/53',
            },
        ]);
    });
});
