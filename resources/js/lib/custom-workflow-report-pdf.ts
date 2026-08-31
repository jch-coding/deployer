import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

export type CustomWorkflowReportIncludeFields = {
    name: boolean;
    device_function: boolean;
    mac_address: boolean;
    site_name: boolean;
    group: boolean;
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
    workflowName: string | null;
    deploymentName: string;
    generatedAt: Date;
    summary: {
        completed: number;
        in_progress: number;
        failed: number;
    };
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

export function workflowDeviceStatusLabel(status: string): string {
    switch (status) {
        case 'completed':
            return 'Complete';
        case 'failed':
            return 'Failed';
        case 'in_progress':
            return 'In progress';
        default:
            return status.replaceAll('_', ' ');
    }
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
): string[] {
    const row = [
        device.serial,
        workflowDeviceStatusLabel(device.overall_status),
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
): string[][] {
    return devices.map((device) =>
        buildReportTableRow(
            device,
            includeFields,
            deviceNotes[device.device_id] ?? '',
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
    workflowName: string | null,
    generatedAt: Date,
): string {
    const datePart = generatedAt.toISOString().slice(0, 10);
    const namePart = slugifyFilenamePart(workflowName ?? 'custom-workflow');

    return `custom-workflow-report-${namePart}-${datePart}.pdf`;
}

export function generateCustomWorkflowReportPdf(
    metadata: CustomWorkflowReportMetadata,
    devices: CustomWorkflowReportDevice[],
    includeFields: CustomWorkflowReportIncludeFields,
    deviceNotes: Record<number, string>,
): Blob {
    const doc = new jsPDF({ orientation: 'landscape' });
    const title = metadata.workflowName?.trim()
        ? `Custom workflow report: ${metadata.workflowName.trim()}`
        : 'Custom workflow report';

    doc.setFontSize(16);
    doc.text(title, 14, 18);

    doc.setFontSize(10);
    doc.text(`Deployment: ${metadata.deploymentName}`, 14, 26);
    doc.text(
        `Generated: ${metadata.generatedAt.toLocaleString()}`,
        14,
        32,
    );
    doc.text(
        `Summary — Complete: ${metadata.summary.completed}, In progress: ${metadata.summary.in_progress}, Failed: ${metadata.summary.failed}`,
        14,
        38,
    );

    autoTable(doc, {
        startY: 44,
        head: [buildReportTableHeaders(includeFields)],
        body: buildReportTableRows(devices, includeFields, deviceNotes),
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
