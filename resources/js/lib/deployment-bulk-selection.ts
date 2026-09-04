export type DeploymentBulkSelectionPayload =
    | { sync_all: true; search?: string }
    | { device_ids: number[] };

/**
 * Build the device-selection body for deployment bulk actions.
 *
 * "Select all matching this search" clears row checkboxes, so callers must pass
 * the client-filtered IDs rather than relying on the backend to re-run search.
 * Unfiltered select-all still uses sync_all to avoid posting every device ID.
 */
export function buildDeploymentBulkSelectionPayload({
    allMatchingSelected,
    search,
    selectedIds,
    filteredDeviceIds,
}: {
    allMatchingSelected: boolean;
    search: string;
    selectedIds: number[];
    filteredDeviceIds: number[];
}): DeploymentBulkSelectionPayload {
    if (!allMatchingSelected) {
        return { device_ids: selectedIds };
    }

    const trimmed = search.trim();
    if (trimmed === '') {
        return { sync_all: true };
    }

    return { device_ids: filteredDeviceIds };
}
