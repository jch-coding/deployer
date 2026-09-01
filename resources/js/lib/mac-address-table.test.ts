import { describe, expect, it } from 'vitest';
import {
    filterMacAddressTableRows,
    matchesMacAddressTableSearch,
    parseMacAddressTableOutput,
} from './mac-address-table';

const SAMPLE_OUTPUT = `MAC age-time            : 300 seconds
Number of MAC addresses : 324

MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
88:22:5b:7d:ff:00    1        dynamic                   1/1/52     
88:22:5b:7d:ff:80    1        dynamic                   1/1/52     
e8:1c:a5:72:ad:c0    1        dynamic                   1/1/52     
e8:1c:a5:72:be:00    1        dynamic                   1/1/52     
e8:1c:a5:72:ef:00    1        dynamic                   1/1/52  `;

describe('parseMacAddressTableOutput', () => {
    it('parses preamble metadata and table rows', () => {
        const result = parseMacAddressTableOutput(SAMPLE_OUTPUT);

        expect(result.ageTime).toBe('300 seconds');
        expect(result.count).toBe(324);
        expect(result.rows).toHaveLength(5);
        expect(result.rows[0]).toEqual({
            mac: '88:22:5b:7d:ff:00',
            vlan: '1',
            type: 'dynamic',
            port: '1/1/52',
        });
        expect(result.rows[4]).toEqual({
            mac: 'e8:1c:a5:72:ef:00',
            vlan: '1',
            type: 'dynamic',
            port: '1/1/52',
        });
    });

    it('returns empty rows when output is unparseable', () => {
        const result = parseMacAddressTableOutput('no table here');

        expect(result.rows).toEqual([]);
        expect(result.ageTime).toBeNull();
        expect(result.count).toBeNull();
        expect(result.raw).toBe('no table here');
    });
});

describe('matchesMacAddressTableSearch', () => {
    const row = {
        mac: '88:22:5b:7d:ff:00',
        vlan: '1',
        type: 'dynamic',
        port: '1/1/52',
    };

    it('matches MAC addresses in multiple formats', () => {
        expect(matchesMacAddressTableSearch(row, '88:22:5b:7d:ff:00')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '88-22-5b-7d-ff-00')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '8822.5b7d.ff00')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '88225B7DFF00')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '88:22:5b')).toBe(true);
    });

    it('matches vlan, type, and port', () => {
        expect(matchesMacAddressTableSearch(row, 'dynamic')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '1/1/52')).toBe(true);
        expect(matchesMacAddressTableSearch(row, '1')).toBe(true);
    });

    it('returns all rows when query is empty', () => {
        expect(filterMacAddressTableRows([row], '')).toEqual([row]);
    });

    it('filters rows that do not match', () => {
        const rows = [
            row,
            {
                mac: 'e8:1c:a5:72:ad:c0',
                vlan: '10',
                type: 'static',
                port: '1/1/1',
            },
        ];

        expect(filterMacAddressTableRows(rows, 'static')).toHaveLength(1);
        expect(filterMacAddressTableRows(rows, 'no-match')).toHaveLength(0);
    });
});
