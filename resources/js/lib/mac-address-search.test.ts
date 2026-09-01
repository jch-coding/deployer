import { describe, expect, it, vi } from 'vitest';
import { matchesAnyTargetMac, parseMacAddressLines } from './mac-address-table';
import { runMacAddressSearch } from './mac-address-search';

const SAMPLE_TABLE = `MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
88:22:5b:7d:ff:00    1        dynamic                   1/1/52     
e8:1c:a5:72:ad:c0    1        dynamic                   1/1/53  `;

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
                    `${value.current}/${value.total}:${value.serial}`,
                );
            },
        });

        expect(fetchMacAddressTableOutput).toHaveBeenCalledTimes(2);
        expect(progress).toEqual(['1/2:SN1', '2/2:SN2']);
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
    });
});
