import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

export type CustomWorkflowReportIncludeFields = {
    name: boolean;
    device_function: boolean;
    mac_address: boolean;
    site_name: boolean;
    group: boolean;
};

export type CustomWorkflowReportStatusLabels = {
    completed: string;
    in_progress: string;
    failed: string;
};

export type CustomWorkflowReportOptions = {
    reportName: string;
    includeDeploymentName: boolean;
    includeTime: boolean;
    statusLabels: CustomWorkflowReportStatusLabels;
};

export const DEFAULT_REPORT_STATUS_LABELS: CustomWorkflowReportStatusLabels = {
    completed: 'Complete',
    in_progress: 'In progress',
    failed: 'Failed',
};

export type CustomWorkflowReportDevice = {
    device_id: number;
    name: string;
    serial: string;
    overall_status: string;
    device_function?: string | null;
    mac_address?: string | null;
    site_name?: string | null;
    group?: string | null;
};

export type CustomWorkflowReportMetadata = {
    deploymentName: string;
    generatedAt: Date;
    summary: {
        completed: number;
        in_progress: number;
        failed: number;
    };
    reportName: string;
    includeDeploymentName: boolean;
    includeTime: boolean;
    statusLabels: CustomWorkflowReportStatusLabels;
};

const OPTIONAL_FIELD_LABELS: Record<
    keyof CustomWorkflowReportIncludeFields,
    string
> = {
    name: 'Name',
    device_function: 'Device function',
    mac_address: 'MAC',
    site_name: 'Site',
    group: 'Group',
};

export function defaultCustomWorkflowReportName(
    workflowName: string | null,
): string {
    return workflowName?.trim() || 'Custom workflow report';
}

export function workflowDeviceStatusLabel(
    status: string,
    labels: CustomWorkflowReportStatusLabels = DEFAULT_REPORT_STATUS_LABELS,
): string {
    switch (status) {
        case 'completed':
            return labels.completed;
        case 'failed':
            return labels.failed;
        case 'in_progress':
            return labels.in_progress;
        default:
            return status.replaceAll('_', ' ');
    }
}

export function formatReportGeneratedAt(
    generatedAt: Date,
    includeTime: boolean,
): string {
    return includeTime
        ? generatedAt.toLocaleString()
        : generatedAt.toLocaleDateString();
}

export function buildReportTableHeaders(
    includeFields: CustomWorkflowReportIncludeFields,
): string[] {
    const headers = ['Serial', 'Status'];

    for (const [key, label] of Object.entries(OPTIONAL_FIELD_LABELS)) {
        if (includeFields[key as keyof CustomWorkflowReportIncludeFields]) {
            headers.push(label);
        }
    }

    headers.push('Notes');

    return headers;
}

export function buildReportTableRow(
    device: CustomWorkflowReportDevice,
    includeFields: CustomWorkflowReportIncludeFields,
    notes: string,
    statusLabels: CustomWorkflowReportStatusLabels = DEFAULT_REPORT_STATUS_LABELS,
): string[] {
    const row = [
        device.serial,
        workflowDeviceStatusLabel(device.overall_status, statusLabels),
    ];

    if (includeFields.name) {
        row.push(device.name);
    }
    if (includeFields.device_function) {
        row.push(device.device_function ?? '');
    }
    if (includeFields.mac_address) {
        row.push(device.mac_address ?? '');
    }
    if (includeFields.site_name) {
        row.push(device.site_name ?? '');
    }
    if (includeFields.group) {
        row.push(device.group ?? '');
    }

    row.push(notes);

    return row;
}

export function buildReportTableRows(
    devices: CustomWorkflowReportDevice[],
    includeFields: CustomWorkflowReportIncludeFields,
    deviceNotes: Record<number, string>,
    statusLabels: CustomWorkflowReportStatusLabels = DEFAULT_REPORT_STATUS_LABELS,
): string[][] {
    return devices.map((device) =>
        buildReportTableRow(
            device,
            includeFields,
            deviceNotes[device.device_id] ?? '',
            statusLabels,
        ),
    );
}

function slugifyFilenamePart(value: string): string {
    const slug = value
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return slug === '' ? 'report' : slug;
}

export function suggestedCustomWorkflowReportFilename(
    reportName: string,
    generatedAt: Date,
): string {
    const datePart = generatedAt.toISOString().slice(0, 10);
    const namePart = slugifyFilenamePart(reportName);

    return `custom-workflow-report-${namePart}-${datePart}.pdf`;
}

export function generateCustomWorkflowReportPdf(
    metadata: CustomWorkflowReportMetadata,
    devices: CustomWorkflowReportDevice[],
    includeFields: CustomWorkflowReportIncludeFields,
    deviceNotes: Record<number, string>,
): Blob {
    const doc = new jsPDF({ orientation: 'landscape' });
    const title = metadata.reportName.trim() || 'Custom workflow report';

    doc.setFontSize(16);
    doc.text(title, 14, 18);

    doc.setFontSize(10);
    let y = 26;

    if (metadata.includeDeploymentName) {
        doc.text(`Deployment: ${metadata.deploymentName}`, 14, y);
        y += 6;
    }

    doc.text(
        `Generated: ${formatReportGeneratedAt(metadata.generatedAt, metadata.includeTime)}`,
        14,
        y,
    );
    y += 6;

    doc.text(
        `Summary — ${metadata.statusLabels.completed}: ${metadata.summary.completed}, ${metadata.statusLabels.in_progress}: ${metadata.summary.in_progress}, ${metadata.statusLabels.failed}: ${metadata.summary.failed}`,
        14,
        y,
    );
    y += 6;

    autoTable(doc, {
        startY: y,
        head: [buildReportTableHeaders(includeFields)],
        body: buildReportTableRows(
            devices,
            includeFields,
            deviceNotes,
            metadata.statusLabels,
        ),
        styles: { fontSize: 9, cellPadding: 2 },
        headStyles: { fillColor: [55, 65, 81] },
    });

    return doc.output('blob');
}

async function writeBlobToSaveFilePicker(
    blob: Blob,
    filename: string,
): Promise<void> {
    const pickerWindow = window as Window & {
        showSaveFilePicker?: (options: {
            suggestedName?: string;
            types?: Array<{
                description: string;
                accept: Record<string, string[]>;
            }>;
        }) => Promise<FileSystemFileHandle>;
    };

    if (typeof pickerWindow.showSaveFilePicker !== 'function') {
        throw new Error('showSaveFilePicker is not available');
    }

    const handle = await pickerWindow.showSaveFilePicker({
        suggestedName: filename,
        types: [
            {
                description: 'PDF document',
                accept: { 'application/pdf': ['.pdf'] },
            },
        ],
    });
    const writable = await handle.createWritable();
    await writable.write(blob);
    await writable.close();
}

function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.click();
    URL.revokeObjectURL(url);
}

export async function saveCustomWorkflowReportPdf(
    blob: Blob,
    filename: string,
): Promise<void> {
    const pickerWindow = window as Window & {
        showSaveFilePicker?: (options: {
            suggestedName?: string;
            types?: Array<{
                description: string;
                accept: Record<string, string[]>;
            }>;
        }) => Promise<FileSystemFileHandle>;
    };

    if (typeof pickerWindow.showSaveFilePicker === 'function') {
        try {
            await writeBlobToSaveFilePicker(blob, filename);

            return;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
        }
    }

    downloadBlob(blob, filename);
}
