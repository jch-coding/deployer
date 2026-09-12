export type WorkflowDeviceSearchStep = {
    step_key: string;
    label: string;
    status: string;
    message: string | null;
    user_overridden?: boolean;
};

export type WorkflowDeviceSearchRow = {
    name: string;
    serial: string;
    overall_status: string;
    status_message: string | null;
    steps: WorkflowDeviceSearchStep[];
};

function searchTokenVariants(token: string): string[] {
    const variants = [token];
    if (token.length > 4 && token.endsWith('ing')) {
        variants.push(token.slice(0, -3));
    }

    return variants;
}

function haystackForDevice(device: WorkflowDeviceSearchRow): string {
    const parts = [
        device.name,
        device.serial,
        device.overall_status,
        device.status_message ?? '',
    ];

    for (const step of device.steps) {
        parts.push(
            step.label,
            step.step_key.replaceAll('_', ' '),
            step.status,
            step.message ?? '',
        );
        if (step.user_overridden) {
            parts.push('user override');
        }
    }

    return parts.join(' ').toLowerCase();
}

export function workflowDeviceMatchesSearch(
    device: WorkflowDeviceSearchRow,
    search: string,
): boolean {
    const tokens = search.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (tokens.length === 0) {
        return true;
    }

    const haystack = haystackForDevice(device);

    return tokens.every((token) =>
        searchTokenVariants(token).some((variant) => haystack.includes(variant)),
    );
}
