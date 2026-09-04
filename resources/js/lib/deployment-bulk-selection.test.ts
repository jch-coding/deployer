import { describe, expect, it } from 'vitest';
import { buildDeploymentBulkSelectionPayload } from './deployment-bulk-selection';

describe('buildDeploymentBulkSelectionPayload', () => {
    it('sends checked row IDs for a partial selection', () => {
        expect(
            buildDeploymentBulkSelectionPayload({
                allMatchingSelected: false,
                search: '6300M',
                selectedIds: [3, 5],
                filteredDeviceIds: [3, 5, 8],
            }),
        ).toEqual({ device_ids: [3, 5] });
    });

    it('syncs the whole deployment when every row is selected with no search', () => {
        expect(
            buildDeploymentBulkSelectionPayload({
                allMatchingSelected: true,
                search: '  ',
                selectedIds: [],
                filteredDeviceIds: [1, 2, 3],
            }),
        ).toEqual({ sync_all: true });
    });

    it('sends client-filtered IDs when selecting all rows that match a search', () => {
        expect(
            buildDeploymentBulkSelectionPayload({
                allMatchingSelected: true,
                search: '6300M',
                selectedIds: [],
                filteredDeviceIds: [11, 14],
            }),
        ).toEqual({ device_ids: [11, 14] });
    });
});
