import { describe, expect, it } from 'vitest';
import {
    emptyDatapathSessionTableFilters,
    expandOffloadFlags,
    expandSessionFlags,
    filterDatapathSessionTableRows,
    formatProtocolLabel,
    parseDatapathSessionTableOutput,
    parseHexCounter,
    protocolDisplayName,
} from './datapath-session-table';

/**
 * Column-aligned fixture matching Aruba `show datapath session` output.
 * Header positions drive fixed-width slicing (same as production CLI).
 */
const SAMPLE_OUTPUT = `Datapath Session Table Entries

------------------------------

Flags: A - Application Firewall Inspect
       C - client, D - deny, E - Media Deep Inspect
       F - fast age, G - media signal, H - high prio
       I - Deep inspect, L - ALG session, M - mirror, N - dest NAT
       O - Session is programmed through SDN/Openflow controller
       P - set prio, R - redirect, S - src NAT, 
       T - set ToS, U - Locally destined, V - VOIP
       X - Http/https redirect for dpi denied session
       Y - no syn
       a - rtp analysis, h - Https redirect error page
       i - in offload flow, m - media mon
       p - Session is marked as permanent
       s - media signal
       d - DPI cache hit
       f - FIB init pending in session
       c - MSCS or SCS session, x - Air Express, y - Application Performance Monitor
RAP Flags: 0 - Q0, 1 - Q1, 2 - Q2, r - redirect to conductor
           t - time based, i - in flow, l - local redirect
Flow Offload Denylist Flags: O - Openflow, E - Default, U - User os unknown, T - Tunnel
                              R - L3 route

Source IP         Destination IP  Prot SPort Dport Cntr Prio ToS Age Destination TAge Packets Bytes Flags  Offload flags 
----------------  --------------  ---- ----- ----- ---- ---- --- --- ----------- ---- ------- ----- ------ ------------- 
10.51.64.24       161.97.2.5      17   34788 53    0    0    0   0   dev34       18   1       48    FCI    E             
10.51.64.39       134.224.4.32    6    49786 443   0    0    0   1   dev35       8ee2 12b     5eb9  Ci                   
10.51.64.39       144.195.13.193  17   64020 8801  0    6    48  0   dev35       83d4 11f70   e7c821 FHPTCVLad E             
C6:D7:C6:45:AB:38                 0806             0    0    0   0   dev34       103  2       1000  F                    
161.97.2.6        10.51.48.110    6    135   58301 0    0    0   1   dev31       59   4       330                        
AP-ARH-239# 
`;

describe('parseDatapathSessionTableOutput', () => {
    it('parses aligned rows including ARP and empty flags', () => {
        const result = parseDatapathSessionTableOutput(SAMPLE_OUTPUT);

        expect(result.rows).toHaveLength(5);

        expect(result.rows[0]).toEqual({
            sourceIp: '10.51.64.24',
            destinationIp: '161.97.2.5',
            prot: '17',
            sport: '34788',
            dport: '53',
            cntr: '0',
            prio: '0',
            tos: '0',
            age: '0',
            destination: 'dev34',
            tage: '18',
            packets: '1',
            bytes: '48',
            flags: 'FCI',
            offloadFlags: 'E',
        });

        expect(result.rows[1]).toMatchObject({
            sourceIp: '10.51.64.39',
            destinationIp: '134.224.4.32',
            prot: '6',
            sport: '49786',
            dport: '443',
            flags: 'Ci',
            offloadFlags: '',
        });

        expect(result.rows[2]).toMatchObject({
            sourceIp: '10.51.64.39',
            destinationIp: '144.195.13.193',
            prot: '17',
            sport: '64020',
            dport: '8801',
            prio: '6',
            tos: '48',
            destination: 'dev35',
            tage: '83d4',
            packets: '11f70',
            bytes: 'e7c821',
            flags: 'FHPTCVLad',
            offloadFlags: 'E',
        });

        expect(result.rows[3]).toMatchObject({
            sourceIp: 'C6:D7:C6:45:AB:38',
            destinationIp: '',
            prot: '0806',
            sport: '',
            dport: '',
            destination: 'dev34',
            flags: 'F',
            offloadFlags: '',
        });

        expect(result.rows[4]).toMatchObject({
            sourceIp: '161.97.2.6',
            destinationIp: '10.51.48.110',
            prot: '6',
            sport: '135',
            dport: '58301',
            flags: '',
            offloadFlags: '',
        });
    });

    it('returns empty rows when output is unparseable', () => {
        const result = parseDatapathSessionTableOutput('no table here');

        expect(result.rows).toEqual([]);
        expect(result.raw).toBe('no table here');
    });
});

describe('flag expansion', () => {
    it('expands session flags case-sensitively', () => {
        expect(expandSessionFlags('FCI')).toEqual([
            'fast age',
            'client',
            'Deep inspect',
        ]);
        expect(expandSessionFlags('FHPTCVLad')).toEqual([
            'fast age',
            'high prio',
            'set prio',
            'set ToS',
            'client',
            'VOIP',
            'ALG session',
            'rtp analysis',
            'DPI cache hit',
        ]);
        expect(expandSessionFlags('Ci')).toEqual(['client', 'in offload flow']);
        expect(expandSessionFlags('Z')).toEqual(['Z']);
    });

    it('expands offload denylist flags', () => {
        expect(expandOffloadFlags('E')).toEqual(['Default']);
        expect(expandOffloadFlags('ORT')).toEqual([
            'Openflow',
            'L3 route',
            'Tunnel',
        ]);
        expect(expandOffloadFlags('')).toEqual([]);
    });
});

describe('protocol and hex helpers', () => {
    it('maps common protocol numbers', () => {
        expect(protocolDisplayName('17')).toBe('UDP');
        expect(protocolDisplayName('6')).toBe('TCP');
        expect(protocolDisplayName('2')).toBe('IGMP');
        expect(protocolDisplayName('0806')).toBe('ARP');
        expect(protocolDisplayName('99')).toBe('99');
        expect(formatProtocolLabel('17')).toBe('UDP');
    });

    it('converts hex counters to decimal', () => {
        expect(parseHexCounter('18')).toBe('24');
        expect(parseHexCounter('23e5')).toBe('9189');
        expect(parseHexCounter('11f70')).toBe('73584');
        expect(parseHexCounter('e7c821')).toBe('15190049');
        expect(parseHexCounter('')).toBe('');
        expect(parseHexCounter('not-hex')).toBe('not-hex');
    });
});

describe('filterDatapathSessionTableRows', () => {
    const rows = parseDatapathSessionTableOutput(SAMPLE_OUTPUT).rows;

    it('returns all rows when filters are empty', () => {
        expect(
            filterDatapathSessionTableRows(
                rows,
                emptyDatapathSessionTableFilters,
            ),
        ).toHaveLength(5);
    });

    it('ANDs column filters and matches flag names', () => {
        const filtered = filterDatapathSessionTableRows(rows, {
            ...emptyDatapathSessionTableFilters,
            sourceIp: '10.51.64.39',
            flags: 'client',
            offloadFlags: 'default',
        });

        expect(filtered).toHaveLength(1);
        expect(filtered[0]?.destinationIp).toBe('144.195.13.193');
    });

    it('matches protocol by name or number', () => {
        expect(
            filterDatapathSessionTableRows(rows, {
                ...emptyDatapathSessionTableFilters,
                prot: 'udp',
            }),
        ).toHaveLength(2);

        expect(
            filterDatapathSessionTableRows(rows, {
                ...emptyDatapathSessionTableFilters,
                prot: '17',
            }),
        ).toHaveLength(2);

        expect(
            filterDatapathSessionTableRows(rows, {
                ...emptyDatapathSessionTableFilters,
                prot: 'arp',
            }),
        ).toHaveLength(1);
    });

    it('matches decimal hex counters', () => {
        const filtered = filterDatapathSessionTableRows(rows, {
            ...emptyDatapathSessionTableFilters,
            packets: '73584',
        });

        expect(filtered).toHaveLength(1);
        expect(filtered[0]?.packets).toBe('11f70');
    });
});
