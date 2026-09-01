import { describe, expect, it } from 'vitest';
import {
    buildReportTableHeaders,
    buildReportTableRow,
    buildReportTableRows,
    formatReportGeneratedAt,
    suggestedCustomWorkflowReportFilename,
    workflowDeviceStatusLabel,
} from './custom-workflow-report-pdf';

const customStatusLabels = {
    completed: 'Done',
    in_progress: 'Working',
    failed: 'Broken',
};

describe('workflowDeviceStatusLabel', () => {
    it('maps workflow device statuses to report labels', () => {
        expect(workflowDeviceStatusLabel('completed')).toBe('Complete');
        expect(workflowDeviceStatusLabel('in_progress')).toBe('In progress');
        expect(workflowDeviceStatusLabel('failed')).toBe('Failed');
    });

    it('uses custom status labels when provided', () => {
        expect(
            workflowDeviceStatusLabel('completed', customStatusLabels),
        ).toBe('Done');
        expect(
            workflowDeviceStatusLabel('in_progress', customStatusLabels),
        ).toBe('Working');
        expect(workflowDeviceStatusLabel('failed', customStatusLabels)).toBe(
            'Broken',
        );
    });
});

describe('formatReportGeneratedAt', () => {
    const generatedAt = new Date('2026-08-31T15:30:00');

    it('formats date only when includeTime is false', () => {
        expect(formatReportGeneratedAt(generatedAt, false)).toBe(
            generatedAt.toLocaleDateString(),
        );
    });

    it('formats date and time when includeTime is true', () => {
        expect(formatReportGeneratedAt(generatedAt, true)).toBe(
            generatedAt.toLocaleString(),
        );
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

    it('uses custom status labels in the status column', () => {
        expect(
            buildReportTableRow(
                device,
                {
                    name: false,
                    device_function: false,
                    mac_address: false,
                    site_name: false,
                    group: false,
                },
                '',
                customStatusLabels,
            ),
        ).toEqual(['CN123', 'Done', '']);
    });
});

describe('suggestedCustomWorkflowReportFilename', () => {
    it('slugifies report name and includes date', () => {
        expect(
            suggestedCustomWorkflowReportFilename(
                'Site rollout report!',
                new Date('2026-08-31T12:00:00.000Z'),
            ),
        ).toBe('custom-workflow-report-site-rollout-report-2026-08-31.pdf');
    });
});
