import { describe, expect, it } from 'vitest';
import {
    buildReportTableHeaders,
    buildReportTableRow,
    buildReportTableRows,
    suggestedCustomWorkflowReportFilename,
    workflowDeviceStatusLabel,
} from './custom-workflow-report-pdf';

describe('workflowDeviceStatusLabel', () => {
    it('maps workflow device statuses to report labels', () => {
        expect(workflowDeviceStatusLabel('completed')).toBe('Complete');
        expect(workflowDeviceStatusLabel('in_progress')).toBe('In progress');
        expect(workflowDeviceStatusLabel('failed')).toBe('Failed');
    });
});

describe('buildReportTableHeaders', () => {
    it('always includes serial, status, and notes', () => {
        expect(
            buildReportTableHeaders({
                name: false,
                device_function: false,
                mac_address: false,
                site_name: false,
                group: false,
            }),
        ).toEqual(['Serial', 'Status', 'Notes']);
    });

    it('includes optional columns when enabled', () => {
        expect(
            buildReportTableHeaders({
                name: true,
                device_function: true,
                mac_address: false,
                site_name: true,
                group: false,
            }),
        ).toEqual([
            'Serial',
            'Status',
            'Name',
            'Device function',
            'Site',
            'Notes',
        ]);
    });
});

describe('buildReportTableRows', () => {
    const device = {
        device_id: 1,
        name: 'AP-Lobby',
        serial: 'CN123',
        overall_status: 'completed',
        device_function: 'CAMPUS_AP',
        mac_address: 'aa:bb:cc:dd:ee:ff',
        site_name: 'HQ',
        group: 'Floor-1',
    };

    it('builds rows with selected optional fields and notes', () => {
        expect(
            buildReportTableRow(
                device,
                {
                    name: true,
                    device_function: false,
                    mac_address: true,
                    site_name: false,
                    group: false,
                },
                'Installed successfully',
            ),
        ).toEqual([
            'CN123',
            'Complete',
            'AP-Lobby',
            'aa:bb:cc:dd:ee:ff',
            'Installed successfully',
        ]);

        expect(
            buildReportTableRows(
                [device],
                {
                    name: true,
                    device_function: false,
                    mac_address: true,
                    site_name: false,
                    group: false,
                },
                { 1: 'Installed successfully' },
            ),
        ).toHaveLength(1);
    });
});

describe('suggestedCustomWorkflowReportFilename', () => {
    it('slugifies workflow name and includes date', () => {
        expect(
            suggestedCustomWorkflowReportFilename(
                'Detail Run!',
                new Date('2026-08-31T12:00:00.000Z'),
            ),
        ).toBe('custom-workflow-report-detail-run-2026-08-31.pdf');
    });
});
