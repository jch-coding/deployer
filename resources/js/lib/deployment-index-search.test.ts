import { describe, expect, it } from 'vitest';
import {
    filterDeploymentsByIndexSearch,
    matchesDeploymentIndexSearch,
    type DeploymentIndexSearchable,
} from '@/lib/deployment-index-search';

const device = {
    name: 'AP-Lobby',
    serial: 'CN12345678',
    mac_address: '88:22:5b:7d:ff:00',
    site: 'Warehouse',
    group: 'Floor-1',
    controller_joined_ip: '10.44.30.27',
};

const deployment: DeploymentIndexSearchable = {
    name: 'HQ Rollout',
    devices: [device],
};

describe('matchesDeploymentIndexSearch', () => {
    it('returns true when query is empty', () => {
        expect(matchesDeploymentIndexSearch(deployment, '')).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, '   ')).toBe(true);
    });

    it('matches deployment name case-insensitively', () => {
        expect(matchesDeploymentIndexSearch(deployment, 'hq roll')).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, 'HQ ROLLOUT')).toBe(
            true,
        );
    });

    it('matches device name, serial, site, and group', () => {
        expect(matchesDeploymentIndexSearch(deployment, 'lobby')).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, 'CN1234')).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, 'warehouse')).toBe(
            true,
        );
        expect(matchesDeploymentIndexSearch(deployment, 'floor-1')).toBe(true);
    });

    it('matches MAC addresses including alternate formats', () => {
        expect(matchesDeploymentIndexSearch(deployment, '88:22:5b')).toBe(true);
        expect(
            matchesDeploymentIndexSearch(deployment, '88-22-5b-7d-ff-00'),
        ).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, '88225B7DFF00')).toBe(
            true,
        );
    });

    it('matches controller_joined_ip when present', () => {
        expect(matchesDeploymentIndexSearch(deployment, '10.44.30')).toBe(true);
        expect(matchesDeploymentIndexSearch(deployment, '10.44.30.27')).toBe(
            true,
        );
    });

    it('skips empty controller_joined_ip', () => {
        const withoutIp: DeploymentIndexSearchable = {
            name: 'No IP Deployment',
            devices: [
                {
                    ...device,
                    name: 'Other',
                    serial: 'SG00000001',
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    site: 'OtherSite',
                    group: 'OtherGroup',
                    controller_joined_ip: null,
                },
                {
                    ...device,
                    name: 'Blank IP',
                    serial: 'SG00000002',
                    mac_address: '11:22:33:44:55:66',
                    site: 'BlankSite',
                    group: 'BlankGroup',
                    controller_joined_ip: '   ',
                },
            ],
        };

        expect(matchesDeploymentIndexSearch(withoutIp, '10.44.30.27')).toBe(
            false,
        );
    });

    it('returns false when nothing matches', () => {
        expect(matchesDeploymentIndexSearch(deployment, 'no-match')).toBe(
            false,
        );
    });
});

describe('filterDeploymentsByIndexSearch', () => {
    it('filters deployments by nested device fields', () => {
        const deployments: DeploymentIndexSearchable[] = [
            deployment,
            {
                name: 'Branch Office',
                devices: [
                    {
                        name: 'SW-Core',
                        serial: 'SG99999999',
                        mac_address: 'e8:1c:a5:72:ad:c0',
                        site: 'Branch',
                        group: 'Core',
                        controller_joined_ip: null,
                    },
                ],
            },
        ];

        expect(filterDeploymentsByIndexSearch(deployments, 'hq')).toHaveLength(
            1,
        );
        expect(
            filterDeploymentsByIndexSearch(deployments, 'e8-1c-a5'),
        ).toHaveLength(1);
        expect(filterDeploymentsByIndexSearch(deployments, '')).toHaveLength(2);
        expect(filterDeploymentsByIndexSearch(deployments, 'zzz')).toHaveLength(
            0,
        );
    });
});
