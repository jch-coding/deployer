import { router } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { MigrationDevice } from '@/lib/migration-csv';
import type { ParsedController, SiteOption } from '@/pages/Migration/migration-types';
import { createDeployment } from '@/routes/migrations';

export type DeviceGroupOption = {
    scopeName: string;
    scopeId: string;
    isClassic?: boolean;
};

type DeviceAssignment = {
    site: string | null;
    group: string | null;
};

type CreateDeploymentFromDevicesDialogProps = {
    devices: MigrationDevice[];
    siteOptions: SiteOption[];
    groupOptions: DeviceGroupOption[];
    parsedControllers: ParsedController[];
    showController?: boolean;
    disabled?: boolean;
};

type LastCreatedDeployment = {
    name: string;
    device_count: number;
};

const NO_CHANGE = '__no_change__';
const NONE = '__none__';

export default function CreateDeploymentFromDevicesDialog({
    devices,
    siteOptions,
    groupOptions,
    parsedControllers,
    showController = false,
    disabled = false,
}: CreateDeploymentFromDevicesDialogProps) {
    const [open, setOpen] = useState(false);
    const [deploymentName, setDeploymentName] = useState('');
    const [selectedSerials, setSelectedSerials] = useState<Set<string>>(() => new Set());
    const [assignments, setAssignments] = useState<Record<string, DeviceAssignment>>({});
    const [bulkSite, setBulkSite] = useState(NO_CHANGE);
    const [bulkGroup, setBulkGroup] = useState(NO_CHANGE);
    const [submitting, setSubmitting] = useState(false);

    const allSelected = devices.length > 0 && selectedSerials.size === devices.length;
    const someSelected = selectedSerials.size > 0 && !allSelected;

    const canApply =
        selectedSerials.size > 0 &&
        (bulkSite !== NO_CHANGE || bulkGroup !== NO_CHANGE);

    const selectedCount = selectedSerials.size;

    const resetState = () => {
        setDeploymentName('');
        setSelectedSerials(new Set());
        setAssignments({});
        setBulkSite(NO_CHANGE);
        setBulkGroup(NO_CHANGE);
        setSubmitting(false);
    };

    const toggleSerial = (serial: string, checked: boolean) => {
        setSelectedSerials((prev) => {
            const next = new Set(prev);
            if (checked) {
                next.add(serial);
            } else {
                next.delete(serial);
            }
            return next;
        });
    };

    const toggleAll = (checked: boolean) => {
        setSelectedSerials(checked ? new Set(devices.map((device) => device.serial)) : new Set());
    };

    const applyToSelected = () => {
        if (!canApply) {
            return;
        }

        setAssignments((prev) => {
            const next = { ...prev };
            for (const serial of selectedSerials) {
                const current = next[serial] ?? { site: null, group: null };
                next[serial] = {
                    site:
                        bulkSite === NO_CHANGE
                            ? current.site
                            : bulkSite === NONE
                              ? null
                              : bulkSite,
                    group:
                        bulkGroup === NO_CHANGE
                            ? current.group
                            : bulkGroup === NONE
                              ? null
                              : bulkGroup,
                };
            }
            return next;
        });
        setBulkSite(NO_CHANGE);
        setBulkGroup(NO_CHANGE);
        setSelectedSerials(new Set());
    };

    const assignmentSummary = useMemo(() => {
        let withSite = 0;
        let withGroup = 0;
        for (const device of devices) {
            const assignment = assignments[device.serial];
            if (assignment?.site) {
                withSite += 1;
            }
            if (assignment?.group) {
                withGroup += 1;
            }
        }
        return { withSite, withGroup };
    }, [assignments, devices]);

    const handleCreate = () => {
        const trimmedName = deploymentName.trim();
        if (trimmedName.length < 3) {
            toast.error('Deployment name must be at least 3 characters');
            return;
        }
        if (devices.length === 0) {
            toast.error('No devices to add to the deployment');
            return;
        }

        setSubmitting(true);

        router.post(
            createDeployment.url(),
            {
                name: trimmedName,
                devices: devices.map((device) => ({
                    name: device.name,
                    serial: device.serial,
                    mac_address: device.mac || null,
                    site: assignments[device.serial]?.site ?? null,
                    group: assignments[device.serial]?.group ?? null,
                })),
                parsed_controllers: parsedControllers,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const created = (page.props as { last_created_deployment?: LastCreatedDeployment | null })
                        .last_created_deployment;
                    const count = created?.device_count ?? devices.length;
                    const name = created?.name ?? trimmedName;
                    toast.success(`Deployment "${name}" created with ${count} devices.`);
                    resetState();
                    setOpen(false);
                },
                onError: (errors) => {
                    const firstError = Object.values(errors)[0];
                    toast.error(
                        typeof firstError === 'string'
                            ? firstError
                            : 'Failed to create deployment',
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                setOpen(nextOpen);
                if (!nextOpen) {
                    resetState();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || devices.length === 0}
                    data-test="create-deployment-from-aps-trigger"
                >
                    <Rocket className="size-4" />
                    Create deployment
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[90vh] max-w-4xl flex-col gap-4 overflow-hidden">
                <DialogHeader>
                    <DialogTitle>Create deployment from AP devices</DialogTitle>
                    <DialogDescription>
                        Name the deployment, optionally assign site and group to device subsets, then
                        create. All listed devices become CAMPUS_AP devices on the new deployment.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
                    <Label htmlFor="migration-deployment-name">Deployment name</Label>
                    <Input
                        id="migration-deployment-name"
                        value={deploymentName}
                        onChange={(event) => setDeploymentName(event.target.value)}
                        placeholder="Deployment name"
                        data-test="migration-deployment-name"
                        disabled={submitting}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={bulkSite === NO_CHANGE ? undefined : bulkSite}
                        onValueChange={setBulkSite}
                        disabled={submitting}
                    >
                        <SelectTrigger
                            className="h-9 w-44"
                            aria-label="Assign site"
                            data-test="migration-bulk-site-select"
                        >
                            <SelectValue placeholder="Select site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_CHANGE}>No change</SelectItem>
                            <SelectItem value={NONE}>None</SelectItem>
                            {siteOptions.map((site) => (
                                <SelectItem key={site.siteId} value={site.siteName}>
                                    {site.siteName}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={bulkGroup === NO_CHANGE ? undefined : bulkGroup}
                        onValueChange={setBulkGroup}
                        disabled={submitting}
                    >
                        <SelectTrigger
                            className="h-9 w-44"
                            aria-label="Assign group"
                            data-test="migration-bulk-group-select"
                        >
                            <SelectValue placeholder="Select group" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_CHANGE}>No change</SelectItem>
                            <SelectItem value={NONE}>None</SelectItem>
                            {groupOptions.map((option) => (
                                <SelectItem key={option.scopeName} value={option.scopeName}>
                                    {option.isClassic ? (
                                        <span className="flex items-center gap-2">
                                            {option.scopeName}
                                            <Badge variant="outline" className="text-xs font-normal">
                                                classic
                                            </Badge>
                                        </span>
                                    ) : (
                                        option.scopeName
                                    )}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canApply || submitting}
                        onClick={applyToSelected}
                        data-test="migration-apply-site-group"
                    >
                        Apply to selected ({selectedCount})
                    </Button>
                    <p className="text-muted-foreground text-xs">
                        {assignmentSummary.withSite} with site · {assignmentSummary.withGroup} with
                        group
                    </p>
                </div>

                <div className="min-h-0 flex-1 overflow-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 sticky top-0">
                            <tr className="border-b text-left">
                                <th className="w-10 px-2 py-2">
                                    <Checkbox
                                        checked={
                                            allSelected
                                                ? true
                                                : someSelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(checked) => toggleAll(checked === true)}
                                        aria-label="Select all devices"
                                        data-test="migration-select-all-devices"
                                        disabled={submitting}
                                    />
                                </th>
                                <th className="px-2 py-2 font-medium">Name</th>
                                <th className="px-2 py-2 font-medium">Serial</th>
                                <th className="px-2 py-2 font-medium">MAC</th>
                                {showController ? (
                                    <th className="px-2 py-2 font-medium">Controller</th>
                                ) : null}
                                <th className="px-2 py-2 font-medium">Site</th>
                                <th className="px-2 py-2 font-medium">Group</th>
                            </tr>
                        </thead>
                        <tbody>
                            {devices.map((device) => {
                                const assignment = assignments[device.serial];
                                const selected = selectedSerials.has(device.serial);

                                return (
                                    <tr
                                        key={`${device.controller ?? ''}-${device.serial}`}
                                        className="border-b"
                                    >
                                        <td className="px-2 py-2">
                                            <Checkbox
                                                checked={selected}
                                                onCheckedChange={(checked) =>
                                                    toggleSerial(device.serial, checked === true)
                                                }
                                                aria-label={`Select ${device.name}`}
                                                disabled={submitting}
                                            />
                                        </td>
                                        <td className="px-2 py-2">{device.name}</td>
                                        <td className="px-2 py-2 font-mono text-xs">
                                            {device.serial}
                                        </td>
                                        <td className="px-2 py-2 font-mono text-xs">{device.mac}</td>
                                        {showController ? (
                                            <td className="px-2 py-2">{device.controller}</td>
                                        ) : null}
                                        <td className="text-muted-foreground px-2 py-2">
                                            {assignment?.site ?? '—'}
                                        </td>
                                        <td className="text-muted-foreground px-2 py-2">
                                            {assignment?.group ?? '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" disabled={submitting}>
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        onClick={handleCreate}
                        disabled={submitting || devices.length === 0}
                        data-test="migration-create-deployment-submit"
                    >
                        {submitting ? 'Creating…' : `Create with ${devices.length} devices`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
