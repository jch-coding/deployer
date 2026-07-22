import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import TaskDurationDialog from '@/components/ui/TaskDurationDialog';
import {
    type AvailableSubscription,
    filterSubscriptionsByDeviceCategory,
} from '@/components/licensing/LicenseSelect';
import RenewLicensingButton from '@/components/licensing/RenewLicensingButton';
import {
    type LicenseTypeOption,
    filterLicenseTypesByDeviceCategory,
} from '@/lib/license-types';
import { collectLicenseTags, poolAvailableSeats } from '@/lib/licensing-pool';
import { isValidMacAddress, normalizeMacAddress } from '@/lib/mac-address';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { check_central_group, check_central_sites, check_lag_port_lists, check_vlan_ip_addresses, force_update_site_scope_ids, greenlake_locations, store } from '@/routes/tasks';
import check_greenlake_inventory from '@/routes/tasks/check_greenlake_inventory';
import FilterIcon from '@/components/ui/FilterIcon';
import { TaskRequiredColumnsInfo } from '@/components/ui/TaskRequiredColumnsInfo';
import { AlarmClockIcon, BoltIcon, CircleCheck, ListIcon, NetworkIcon, PlusIcon, RefreshCw, Trash2Icon } from 'lucide-react';

type DeviceType = {
    id: number;
    name: string;
    completed: boolean;
    device_function: string;
    serial?: string | number;
    mac_address?: string | null;
    in_greenlake_inventory?: boolean;
};

function deviceFilterLabel(device: DeviceType): string {
    return device.serial ? `${device.name} (${device.serial})` : device.name;
}

type DeploymentType = {
    id: number,
    name: string,
}

type PerDeviceLicenseSelection = {
    license_tag: string;
    license_type: LicenseTypeOption | '';
};

type GreenLakeTagRow = {
    key: string;
    value: string;
};

type GreenLakeLocationOption = {
    id: string;
    name: string;
};

type TaskCardProps = {
    task: string;
    task_friendly_name: string;
    task_friendly_description: string;
    required_columns: string[];
    devices: DeviceType[];
    deployment: DeploymentType;
    requiresClassicCentral?: boolean;
    available_subscriptions?: AvailableSubscription[];
    licensing_synced_at?: string | null;
    license_tags?: string[];
    license_type_options?: LicenseTypeOption[];
    cx_firmware_versions?: string[];
    central_firmware_error?: string | null;
};

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export default function TaskCard({
    task,
    task_friendly_name,
    task_friendly_description,
    required_columns,
    devices,
    deployment,
    requiresClassicCentral = false,
    available_subscriptions = [],
    licensing_synced_at = null,
    license_tags: licenseTagsProp = [],
    license_type_options = [],
    cx_firmware_versions = [],
    central_firmware_error = null,
}: TaskCardProps) {
    const [taskDevices, setTaskDevices] = useState<DeviceType[]>([])
    const [isLaunching, setIsLaunching] = useState(false)
    const [switchesOnly, setSwitchesOnly] = useState(false)
    const [apsOnly, setAPsOnly] = useState(false)
    const [deviceSearch, setDeviceSearch] = useState('')
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0)
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(10)
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(0)
    const [vlanSitePrefix, setVlanSitePrefix] = useState('')
    const [firmwareComplianceVersion, setFirmwareComplianceVersion] = useState('')
    const [bulkLicenseTag, setBulkLicenseTag] = useState('');
    const [bulkLicenseType, setBulkLicenseType] = useState<LicenseTypeOption | ''>('');
    const [perDeviceLicenseSelections, setPerDeviceLicenseSelections] = useState<
        Record<number, PerDeviceLicenseSelection>
    >({});
    const [macModalOpen, setMacModalOpen] = useState(false);
    const [macDrafts, setMacDrafts] = useState<Record<number, string>>({});
    const [macModalDevices, setMacModalDevices] = useState<DeviceType[]>([]);
    const [macSaving, setMacSaving] = useState(false);
    const [deviceMacOverrides, setDeviceMacOverrides] = useState<Record<number, string>>({});
    const [pendingDeployAllDevices, setPendingDeployAllDevices] = useState(false);
    const [isCheckingGreenLake, setIsCheckingGreenLake] = useState(false);
    const [greenLakeCheckComplete, setGreenLakeCheckComplete] = useState(false);
    const [greenLakeCheckResult, setGreenLakeCheckResult] = useState<{
        passedCount: number;
        failedDevices: string[];
        error?: string;
    } | null>(null);
    const [greenLakeTags, setGreenLakeTags] = useState<GreenLakeTagRow[]>([]);
    const [greenLakeLocations, setGreenLakeLocations] = useState<GreenLakeLocationOption[]>([]);
    const [greenLakeLocationId, setGreenLakeLocationId] = useState('');
    const [greenLakeLocationsLoading, setGreenLakeLocationsLoading] = useState(false);
    const [greenLakeLocationsError, setGreenLakeLocationsError] = useState<string | null>(null);
    const [greenLakeProgress, setGreenLakeProgress] = useState<{
        current: number;
        total: number;
        percent: number;
        message: string;
    }>({
        current: 0,
        total: 0,
        percent: 0,
        message: 'Starting GreenLake inventory check...',
    });

    const isAssignSubscription = task === 'ASSIGN_SUBSCRIPTION';
    const isUnassignSubscription = task === 'UNASSIGN_SUBSCRIPTION';
    const isLicensingTask = isAssignSubscription || isUnassignSubscription;
    const isAddToGreenLakeInventory = task === 'ADD_DEVICES_TO_GREENLAKE_INVENTORY';
    const isAddTagsToGreenLake = task === 'ADD_TAGS_TO_GREENLAKE_DEVICES';
    const isAddLocationToGreenLake = task === 'ADD_LOCATION_TO_GREENLAKE_DEVICES';
    const needsGreenLakeLocations = isAddToGreenLakeInventory || isAddLocationToGreenLake;

    const devicesWithMac = useMemo(
        () =>
            devices.map((device) => ({
                ...device,
                mac_address:
                    deviceMacOverrides[device.id] ?? device.mac_address ?? null,
            })),
        [devices, deviceMacOverrides],
    );

    const filteredDevices = useMemo(() => {
        const q = deviceSearch.trim().toLowerCase();
        return devicesWithMac.filter((device) => {
            const typeOk = switchesOnly
                ? device.device_function === 'ACCESS_SWITCH'
                : apsOnly
                  ? device.device_function === 'CAMPUS_AP'
                  : true;
            if (!typeOk) return false;
            if (!q) return true;
            const serial = String(device.serial ?? '').toLowerCase();
            return device.name.toLowerCase().includes(q) || serial.includes(q);
        });
    }, [devicesWithMac, switchesOnly, apsOnly, deviceSearch]);

    const filteredSubscriptions = useMemo(
        () => filterSubscriptionsByDeviceCategory(available_subscriptions, switchesOnly, apsOnly),
        [available_subscriptions, switchesOnly, apsOnly],
    );

    const availableLicenseTags = useMemo(() => {
        const fromSubscriptions = collectLicenseTags(filteredSubscriptions);
        const merged = new Set([...licenseTagsProp, ...fromSubscriptions]);

        return [...merged].sort((a, b) => a.localeCompare(b));
    }, [filteredSubscriptions, licenseTagsProp]);

    const filteredLicenseTypes = useMemo(
        () => filterLicenseTypesByDeviceCategory(license_type_options, switchesOnly, apsOnly),
        [license_type_options, switchesOnly, apsOnly],
    );

    const bulkPoolSeats = useMemo(() => {
        if (!bulkLicenseTag || !bulkLicenseType) {
            return 0;
        }

        return poolAvailableSeats(filteredSubscriptions, bulkLicenseTag, bulkLicenseType);
    }, [filteredSubscriptions, bulkLicenseTag, bulkLicenseType]);

    useEffect(() => {
        if (
            availableLicenseTags.length > 0 &&
            !availableLicenseTags.includes(bulkLicenseTag)
        ) {
            setBulkLicenseTag(availableLicenseTags[0]);
        }
    }, [availableLicenseTags, bulkLicenseTag]);

    useEffect(() => {
        if (
            filteredLicenseTypes.length > 0 &&
            bulkLicenseType !== '' &&
            !filteredLicenseTypes.includes(bulkLicenseType)
        ) {
            setBulkLicenseType(filteredLicenseTypes[0]);
        }
    }, [filteredLicenseTypes, bulkLicenseType]);

    useEffect(() => {
        if (bulkLicenseType === '' && filteredLicenseTypes.length > 0) {
            setBulkLicenseType(filteredLicenseTypes[0]);
        }
    }, [filteredLicenseTypes, bulkLicenseType]);

    useEffect(() => {
        if (!needsGreenLakeLocations) {
            return;
        }

        let cancelled = false;
        setGreenLakeLocationsLoading(true);
        setGreenLakeLocationsError(null);

        fetch(greenlake_locations.url(deployment.id), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const data = (await response.json().catch(() => ({}))) as {
                    locations?: GreenLakeLocationOption[];
                    message?: string;
                };
                if (!response.ok) {
                    throw new Error(
                        data.message ?? 'Failed to load GreenLake locations.',
                    );
                }

                return data.locations ?? [];
            })
            .then((locations) => {
                if (cancelled) {
                    return;
                }
                setGreenLakeLocations(locations);
            })
            .catch((error: unknown) => {
                if (cancelled) {
                    return;
                }
                setGreenLakeLocations([]);
                setGreenLakeLocationsError(
                    error instanceof Error
                        ? error.message
                        : 'Failed to load GreenLake locations.',
                );
            })
            .finally(() => {
                if (!cancelled) {
                    setGreenLakeLocationsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [needsGreenLakeLocations, deployment.id]);

    const vlanPrefixTrimmed = vlanSitePrefix.trim()
    const isAddVlansWithPrefix =
        task === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== ''

    const handleCheckboxChange = (deviceId : number, checked : boolean) => {
        const newDevice = devicesWithMac.find(device => device.id === deviceId)
        if (checked && newDevice) {
            setTaskDevices([...taskDevices, { ...newDevice, completed: false }])
        } else {
            if (taskDevices.find(device => device.id === deviceId)) {
                setTaskDevices(taskDevices.filter(device => device.id !== deviceId))
            }
        }
    }

    const openMacModalForDevices = (devicesList: DeviceType[]) => {
        const drafts: Record<number, string> = {};
        for (const device of devicesList) {
            drafts[device.id] = String(device.mac_address ?? '');
        }
        setMacModalDevices(devicesList);
        setMacDrafts(drafts);
        setMacModalOpen(true);
    };

    const devicesMissingMac = (devicesList: DeviceType[]) =>
        devicesList.filter((device) => !normalizeMacAddress(String(device.mac_address ?? '')));

    const closeGreenLakeInventoryCheck = () => {
        setIsCheckingGreenLake(false);
        setGreenLakeCheckComplete(false);
        setGreenLakeCheckResult(null);
        setGreenLakeProgress({
            current: 0,
            total: 0,
            percent: 0,
            message: 'Starting GreenLake inventory check...',
        });
    };

    const checkGreenLakeInventory = async () => {
        if (isCheckingGreenLake) {
            return;
        }

        type StepResponse = {
            progress: {
                current: number;
                total: number;
                percent: number;
                message: string;
            };
            partial: {
                missing_from_inventory: string[];
                missing_mac: string[];
            };
            done: boolean;
            summary?: {
                ok: boolean;
                message: string;
                passed_count: number;
                failed_devices: string[];
            };
        };

        setIsCheckingGreenLake(true);
        setGreenLakeCheckComplete(false);
        setGreenLakeCheckResult(null);
        setGreenLakeProgress({
            current: 0,
            total: 0,
            percent: 0,
            message: 'Starting GreenLake inventory check...',
        });

        try {
            let step = 0;
            let total = 1;
            let summary: StepResponse['summary'];

            while (step < total) {
                const response = await fetch(
                    check_greenlake_inventory.step.url([deployment.id, step]),
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    },
                );

                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    throw new Error(
                        body?.message ?? `Check failed (HTTP ${response.status}).`,
                    );
                }

                const data = (await response.json()) as StepResponse;
                setGreenLakeProgress(data.progress);
                total = data.progress.total;
                if (data.summary) {
                    summary = data.summary;
                }
                step += 1;

                if (data.done) {
                    break;
                }
            }

            if (summary) {
                setGreenLakeCheckResult({
                    passedCount: summary.passed_count,
                    failedDevices: summary.failed_devices,
                });
            } else {
                setGreenLakeCheckResult({
                    passedCount: 0,
                    failedDevices: [],
                    error: 'Check finished without a summary.',
                });
            }
        } catch (error) {
            setGreenLakeCheckResult({
                passedCount: 0,
                failedDevices: [],
                error:
                    error instanceof Error
                        ? error.message
                        : 'Failed to verify GreenLake inventory.',
            });
        } finally {
            setGreenLakeCheckComplete(true);
        }
    };

    const saveMacAddresses = async (): Promise<boolean> => {
        const invalid = macModalDevices.some((device) => {
            const draft = macDrafts[device.id] ?? '';
            return !isValidMacAddress(draft);
        });
        if (invalid) {
            toast.error('Enter a valid MAC address for each device (e.g. aa:bb:cc:dd:ee:ff).');

            return false;
        }

        setMacSaving(true);
        try {
            for (const device of macModalDevices) {
                const normalized = normalizeMacAddress(macDrafts[device.id] ?? '');
                if (!normalized) {
                    toast.error('Enter a valid MAC address for each device (e.g. aa:bb:cc:dd:ee:ff).');

                    return false;
                }

                await new Promise<void>((resolve, reject) => {
                    router.patch(
                        `/devices/${device.id}`,
                        { mac_address: normalized },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setDeviceMacOverrides((prev) => ({
                                    ...prev,
                                    [device.id]: normalized,
                                }));
                                resolve();
                            },
                            onError: () => reject(new Error('Failed to save MAC')),
                        },
                    );
                });
            }

            toast.success('MAC addresses saved.');

            return true;
        } catch {
            toast.error('Failed to save one or more MAC addresses.');

            return false;
        } finally {
            setMacSaving(false);
        }
    };

    const resolveDevicesForDispatch = (devicesList: DeviceType[], allDevices: boolean) => {
        const vlanPrefixMode = task === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== '';

        if (vlanPrefixMode) {
            return [];
        }

        if (allDevices) {
            return devicesList;
        }

        return devicesList.filter(
            (device) => taskDevices.find((dev) => device.id === dev.id) !== undefined,
        );
    };

    const dispatch_task_with_devices = (
        taskStr: string,
        devicesList: DeviceType[],
        allDevices = false,
        licensingMode: 'uniform' | 'per_device' = 'uniform',
        skipMacGate = false,
    ) => {
        const devices_for_task = resolveDevicesForDispatch(devicesList, allDevices);

        if (devices_for_task.length === 0 && !(taskStr === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== '')) {
            toast.error('Select at least one device.');

            return;
        }

        if (taskStr === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== '' && firmwareComplianceVersion.trim() === '') {
            toast.error('Select a CX firmware compliance version.');

            return;
        }

        if (taskStr === 'ADD_DEVICES_TO_GREENLAKE_INVENTORY' && !skipMacGate) {
            const missing = devicesMissingMac(devices_for_task);
            if (missing.length > 0) {
                const serials = missing
                    .map((device) => String(device.serial ?? device.name))
                    .join(', ');
                toast.error(`The following devices are missing mac_address: ${serials}.`);
                setPendingDeployAllDevices(allDevices);
                openMacModalForDevices(missing);

                return;
            }
        }

        if (taskStr === 'ADD_DEVICES_TO_GREENLAKE_INVENTORY') {
            const notInInventory = devices_for_task.filter(
                (device) => !device.in_greenlake_inventory,
            );
            if (notInInventory.length === 0) {
                toast.error('All selected devices are already in GreenLake inventory.');

                return;
            }
        }

        if (taskStr === 'ADD_TAGS_TO_GREENLAKE_DEVICES') {
            const hasTagKey = greenLakeTags.some((row) => row.key.trim() !== '');
            if (!hasTagKey) {
                toast.error('Add at least one GreenLake tag key before launching this task.');

                return;
            }
        }

        if (taskStr === 'ADD_LOCATION_TO_GREENLAKE_DEVICES') {
            if (!greenLakeLocationId) {
                toast.error('Select a GreenLake location before launching this task.');

                return;
            }

            const inInventory = devices_for_task.filter(
                (device) => device.in_greenlake_inventory,
            );
            if (inInventory.length === 0) {
                toast.error('None of the selected devices are in GreenLake inventory.');

                return;
            }
        }

        if (taskStr === 'ASSIGN_SUBSCRIPTION') {
            if (licensingMode === 'uniform') {
                if (!bulkLicenseTag) {
                    toast.error('Select a license tag.');

                    return;
                }
                if (!bulkLicenseType) {
                    toast.error('Select a license type.');

                    return;
                }
                if (devices_for_task.length > bulkPoolSeats) {
                    toast.error(
                        `Only ${bulkPoolSeats} ${bulkLicenseType} seat(s) available for tag "${bulkLicenseTag}".`,
                    );

                    return;
                }
            }
            if (licensingMode === 'per_device') {
                const missing = devices_for_task.some((device) => {
                    const selection = perDeviceLicenseSelections[device.id];

                    return !selection?.license_tag || !selection?.license_type;
                });
                if (missing) {
                    toast.error('Select a license tag and type for each device in the modal.');

                    return;
                }

                const poolCounts = new Map<string, number>();
                for (const device of devices_for_task) {
                    const selection = perDeviceLicenseSelections[device.id];
                    if (!selection?.license_tag || !selection?.license_type) {
                        continue;
                    }
                    const key = `${selection.license_tag}|${selection.license_type}`;
                    poolCounts.set(key, (poolCounts.get(key) ?? 0) + 1);
                }

                for (const [key, count] of poolCounts.entries()) {
                    const [tag, licenseType] = key.split('|') as [string, LicenseTypeOption];
                    const available = poolAvailableSeats(filteredSubscriptions, tag, licenseType);
                    if (count > available) {
                        toast.error(
                            `Only ${available} ${licenseType} seat(s) available for tag "${tag}".`,
                        );

                        return;
                    }
                }
            }
        }

        setTaskDevices(devices_for_task);
        setIsLaunching(true);
        const deploymentTimeTotalMinutes = deploymentTimeHours * 60 + deploymentTimeMinutes;

        const devicePayload = devices_for_task.map((device) => {
            if (taskStr === 'ASSIGN_SUBSCRIPTION' && licensingMode === 'per_device') {
                const selection = perDeviceLicenseSelections[device.id];

                return {
                    id: device.id,
                    license_tag: selection?.license_tag ?? '',
                    license_type: selection?.license_type ?? '',
                };
            }
            return { id: device.id };
        });

        router.post(store(deployment.id).url, {
            task_type: taskStr,
            devices: devicePayload,
            deployment_time: deploymentTimeTotalMinutes,
            wait_time: waitTimeMinutes,
            licensing_mode: isLicensingTask ? licensingMode : undefined,
            license_tag:
                taskStr === 'ASSIGN_SUBSCRIPTION' && licensingMode === 'uniform'
                    ? bulkLicenseTag
                    : undefined,
            license_type:
                taskStr === 'ASSIGN_SUBSCRIPTION' && licensingMode === 'uniform'
                    ? bulkLicenseType
                    : undefined,
            ...(taskStr === 'ADD_DEVICES_TO_GREENLAKE_INVENTORY'
                ? {
                      tags: greenLakeTags
                          .map((row) => ({
                              key: row.key.trim(),
                              value: row.value.trim(),
                          }))
                          .filter((row) => row.key !== ''),
                      greenlake_location_id: greenLakeLocationId || undefined,
                  }
                : {}),
            ...(taskStr === 'ADD_TAGS_TO_GREENLAKE_DEVICES'
                ? {
                      tags: greenLakeTags
                          .map((row) => ({
                              key: row.key.trim(),
                              value: row.value.trim(),
                          }))
                          .filter((row) => row.key !== ''),
                  }
                : {}),
            ...(taskStr === 'ADD_LOCATION_TO_GREENLAKE_DEVICES'
                ? {
                      greenlake_location_id: greenLakeLocationId || undefined,
                  }
                : {}),
            ...(taskStr === 'ADD_VLANS_TO_DEVICE_GROUP'
                ? {
                      vlan_site_prefix: vlanPrefixTrimmed,
                      firmware_compliance_version:
                          vlanPrefixTrimmed !== '' ? firmwareComplianceVersion.trim() : undefined,
                  }
                : {}),
        }, {
            onError: (errors) => {
                setIsLaunching(false);
                const message = Object.values(errors)
                    .flat()
                    .find((value) => typeof value === 'string' && value.trim() !== '');
                if (message) {
                    toast.error(message);
                }
            },
        });
    };

    const handleSaveMacAndDeploy = async () => {
        const saved = await saveMacAddresses();
        if (!saved) {
            return;
        }

        setMacModalOpen(false);
        const updatedDevices = devicesWithMac.map((device) => {
            const draft = macDrafts[device.id];
            if (!draft) {
                return device;
            }
            const normalized = normalizeMacAddress(draft);

            return normalized ? { ...device, mac_address: normalized } : device;
        });

        dispatch_task_with_devices(
            task,
            updatedDevices,
            pendingDeployAllDevices,
            'uniform',
            true,
        );
    };

    return (
        <Card className="h-full w-96">
            <CardHeader className="relative pr-10">
                <TaskRequiredColumnsInfo columns={required_columns} />
                <CardTitle>{task_friendly_name}</CardTitle>
                {requiresClassicCentral ? (
                    <Badge variant="outline" className="mt-1 w-fit font-normal">
                        Classic Central API
                    </Badge>
                ) : null}
                <CardDescription>{task_friendly_description}</CardDescription>
                {task === 'ADD_VLANS_TO_DEVICE_GROUP' ? (
                    <div className="mt-3 space-y-1">
                        <label htmlFor="vlan-site-prefix" className="text-sm font-medium">
                            Site prefix (optional)
                        </label>
                        <Input
                            id="vlan-site-prefix"
                            type="text"
                            placeholder="e.g. SAC"
                            value={vlanSitePrefix}
                            onChange={(e) => setVlanSitePrefix(e.target.value)}
                            className="max-w-[12rem]"
                            autoComplete="off"
                            data-test="vlan-site-prefix"
                            aria-describedby="vlan-site-prefix-hint"
                        />
                        <p id="vlan-site-prefix-hint" className="text-muted-foreground text-xs">
                            When set, VLANs are pushed to WHSE-{'{prefix}'}-ACCESS, CORE, MGMT, DMZ, and SERVER without
                            using device rows. Leave blank to use each selected device&apos;s group.
                        </p>
                        {vlanPrefixTrimmed !== '' ? (
                            <div className="space-y-1 pt-2">
                                <label
                                    htmlFor="cx-firmware-compliance"
                                    className="text-sm font-medium"
                                >
                                    CX firmware compliance
                                </label>
                                <select
                                    id="cx-firmware-compliance"
                                    value={firmwareComplianceVersion}
                                    onChange={(e) => setFirmwareComplianceVersion(e.target.value)}
                                    className={selectClassName}
                                    data-test="cx-firmware-compliance-select"
                                >
                                    <option value="">
                                        {central_firmware_error
                                            ? 'Could not load firmware versions'
                                            : cx_firmware_versions.length === 0
                                              ? 'No firmware versions available'
                                              : 'Select a version'}
                                    </option>
                                    {cx_firmware_versions.map((version) => (
                                        <option key={version} value={version}>
                                            {version}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    Required for site-prefix deploys. Sets CX firmware compliance on all five
                                    WHSE groups before VLAN templates are applied.
                                </p>
                                {central_firmware_error ? (
                                    <p className="text-destructive text-xs">{central_firmware_error}</p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                ) : null}
                {isAddToGreenLakeInventory || isAddTagsToGreenLake || isAddLocationToGreenLake ? (
                    <div className="mt-3 space-y-2">
                        {needsGreenLakeLocations ? (
                            <div className="space-y-1">
                                <label
                                    htmlFor={`greenlake-location-${task}`}
                                    className="text-sm font-medium"
                                >
                                    {isAddLocationToGreenLake
                                        ? 'Location'
                                        : 'Location (optional)'}
                                </label>
                                <select
                                    id={`greenlake-location-${task}`}
                                    value={greenLakeLocationId}
                                    onChange={(e) => setGreenLakeLocationId(e.target.value)}
                                    className={selectClassName}
                                    data-test="greenlake-location-select"
                                    disabled={greenLakeLocationsLoading}
                                >
                                    <option value="">
                                        {greenLakeLocationsLoading
                                            ? 'Loading locations...'
                                            : greenLakeLocationsError
                                              ? 'Could not load locations'
                                              : greenLakeLocations.length === 0
                                                ? 'No locations available'
                                                : isAddLocationToGreenLake
                                                  ? 'Select a location'
                                                  : 'No location'}
                                    </option>
                                    {greenLakeLocations.map((location) => (
                                        <option key={location.id} value={location.id}>
                                            {location.name}
                                        </option>
                                    ))}
                                </select>
                                {greenLakeLocationsError ? (
                                    <p className="text-destructive text-xs">{greenLakeLocationsError}</p>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        Must already exist in GreenLake. Applied to all selected devices.
                                    </p>
                                )}
                            </div>
                        ) : null}
                        {isAddToGreenLakeInventory || isAddTagsToGreenLake ? (
                            <>
                                <div className="flex items-center justify-between gap-2">
                                    <label className="text-sm font-medium">
                                        {isAddTagsToGreenLake
                                            ? 'GreenLake tags'
                                            : 'GreenLake tags (optional)'}
                                    </label>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-test="add-greenlake-tag"
                                        onClick={() =>
                                            setGreenLakeTags((prev) => [
                                                ...prev,
                                                { key: '', value: '' },
                                            ])
                                        }
                                    >
                                        <PlusIcon className="size-3.5" aria-hidden />
                                        Add tag
                                    </Button>
                                </div>
                                {greenLakeTags.length === 0 ? (
                                    <p className="text-muted-foreground text-xs">
                                        {isAddTagsToGreenLake
                                            ? 'Add at least one key–value tag to apply to all selected devices.'
                                            : 'No tags. Add key–value pairs to apply to all selected devices.'}
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {greenLakeTags.map((row, index) => (
                                            <div
                                                key={index}
                                                className="flex flex-wrap items-center gap-2"
                                                data-test="greenlake-tag-row"
                                            >
                                                <Input
                                                    type="text"
                                                    placeholder="Key"
                                                    value={row.key}
                                                    onChange={(e) =>
                                                        setGreenLakeTags((prev) =>
                                                            prev.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          key: e.target.value,
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                    className="min-w-[7rem] flex-1"
                                                    autoComplete="off"
                                                    aria-label={`Tag key ${index + 1}`}
                                                    data-test="greenlake-tag-key"
                                                />
                                                <Input
                                                    type="text"
                                                    placeholder="Value (optional)"
                                                    value={row.value}
                                                    onChange={(e) =>
                                                        setGreenLakeTags((prev) =>
                                                            prev.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          value: e.target.value,
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                    className="min-w-[7rem] flex-1"
                                                    autoComplete="off"
                                                    aria-label={`Tag value ${index + 1}`}
                                                    data-test="greenlake-tag-value"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="shrink-0"
                                                    aria-label={`Remove tag ${index + 1}`}
                                                    data-test="remove-greenlake-tag"
                                                    onClick={() =>
                                                        setGreenLakeTags((prev) =>
                                                            prev.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    <Trash2Icon className="size-4" aria-hidden />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="text-muted-foreground text-xs">
                                    Applied to all selected devices. Value may be left blank.
                                </p>
                            </>
                        ) : null}
                    </div>
                ) : null}
                {isAssignSubscription ? (
                    <div className="mt-3 space-y-2">
                        <div className="flex justify-end">
                            <RenewLicensingButton
                                licensingSyncedAt={licensing_synced_at}
                                size="sm"
                            />
                        </div>
                        <label htmlFor={`bulk-license-tag-${task}`} className="text-sm font-medium">
                            License tag
                        </label>
                        <select
                            id={`bulk-license-tag-${task}`}
                            value={bulkLicenseTag}
                            onChange={(e) => setBulkLicenseTag(e.target.value)}
                            className={selectClassName}
                            data-test="license-tag-select"
                        >
                            <option value="">
                                {availableLicenseTags.length === 0
                                    ? 'No license tags available'
                                    : 'Select a tag'}
                            </option>
                            {availableLicenseTags.map((tag) => (
                                <option key={tag} value={tag}>
                                    {tag}
                                </option>
                            ))}
                        </select>
                        <label htmlFor={`bulk-license-type-${task}`} className="text-sm font-medium">
                            License type
                        </label>
                        <select
                            id={`bulk-license-type-${task}`}
                            value={bulkLicenseType}
                            onChange={(e) =>
                                setBulkLicenseType(e.target.value as LicenseTypeOption)
                            }
                            className={selectClassName}
                            data-test="license-type-select"
                        >
                            <option value="">
                                {filteredLicenseTypes.length === 0
                                    ? 'No license types available'
                                    : 'Select a type'}
                            </option>
                            {filteredLicenseTypes.map((licenseType) => (
                                <option key={licenseType} value={licenseType}>
                                    {licenseType}
                                </option>
                            ))}
                        </select>
                        {bulkLicenseTag && bulkLicenseType ? (
                            <p className="text-muted-foreground text-xs">
                                {bulkPoolSeats} seat{bulkPoolSeats === 1 ? '' : 's'} available in
                                this tag/type pool.
                            </p>
                        ) : null}
                        <p className="text-muted-foreground text-xs">
                            Applies to all devices when deploying. Use per-device modal for mixed
                            tag/type combinations.
                        </p>
                    </div>
                ) : null}
                {isUnassignSubscription ? (
                    <div className="mt-3 flex justify-end">
                        <RenewLicensingButton
                            licensingSyncedAt={licensing_synced_at}
                            size="sm"
                        />
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className="mt-auto flex w-full flex-wrap items-center gap-2">
                <div className="flex flex-wrap items-center gap-2">
                <TaskDurationDialog
                    deploymentTimeHours={deploymentTimeHours}
                    deploymentTimeMinutes={deploymentTimeMinutes}
                    waitTimeMinutes={waitTimeMinutes}
                    onDeploymentTimeHoursChange={setDeploymentTimeHours}
                    onDeploymentTimeMinutesChange={setDeploymentTimeMinutes}
                    onWaitTimeMinutesChange={setWaitTimeMinutes}
                    trigger={
                        <Button
                            type="button"
                            size="icon"
                            className="rounded-full"
                            data-test="set-deployment-time"
                            aria-label="Set duration"
                        >
                            <AlarmClockIcon className="size-4" aria-hidden />
                        </Button>
                    }
                    footer={
                        <DialogClose asChild>
                            <Button className="hover:bg-slate-300">Set Duration</Button>
                        </DialogClose>
                    }
                />
                <Dialog>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <DialogTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="associate-devices-with-task"
                                    aria-label="Filter devices"
                                >
                                    <span className="inline-flex size-4 items-center justify-center [&_svg]:size-full">
                                        <FilterIcon color="currentColor" />
                                    </span>
                                </Button>
                            </DialogTrigger>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <p>Filter devices</p>
                        </TooltipContent>
                    </Tooltip>
                    <DialogContent>
                        <DialogTitle>Associate Devices</DialogTitle>
                        <DialogDescription>
                            Filter devices associated with this task
                        </DialogDescription>
                            <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setSwitchesOnly(!switchesOnly)}>
                                        {switchesOnly ? 'Switches Only' : 'All Devices'}
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => setAPsOnly(!apsOnly)}>
                                        {apsOnly ? 'APs Only' : 'All Devices'}
                                    </Button>
                                    <Input
                                        type="search"
                                        placeholder="Search name or serial…"
                                        value={deviceSearch}
                                        onChange={(e) => setDeviceSearch(e.target.value)}
                                        className="min-w-[10rem] flex-1"
                                        aria-label="Search devices by name or serial"
                                    />
                                </div>
                        {
                            devices.length > 0 ?
                                filteredDevices.length > 0 ? (
                                    filteredDevices.map((device) => (
                                        <div className="flex gap-2" key={device.id}>
                                            <Checkbox
                                                id={`task-card-device-${device.id}`}
                                                checked={taskDevices.find(dev => dev.id === device.id) !== undefined}
                                                onCheckedChange={(checked) =>
                                                    handleCheckboxChange(device.id, checked === true)
                                                }
                                            />
                                            <label htmlFor={`task-card-device-${device.id}`}>
                                                {deviceFilterLabel(device)}
                                            </label>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-muted-foreground text-sm">No devices match your filters.</p>
                                ) :
                            <p>Add devices to deployment before adding tasks</p>
                        }
                            </div>
                    </DialogContent>
                </Dialog>
                {isLicensingTask ? (
                    <Dialog>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <DialogTrigger asChild>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        className="rounded-full"
                                        data-test="per-device-licenses"
                                        aria-label="Per-device licenses"
                                    >
                                        <ListIcon className="size-4" aria-hidden />
                                    </Button>
                                </DialogTrigger>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Per-device licenses</p>
                            </TooltipContent>
                        </Tooltip>
                        <DialogContent className="max-w-lg">
                            <DialogTitle>Per-device licenses</DialogTitle>
                            <DialogDescription>
                                {isAssignSubscription
                                    ? 'Assign a different license to each device, then deploy selected.'
                                    : 'Select devices to unassign, then deploy selected.'}
                            </DialogDescription>
                            <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                                {filteredDevices.length > 0 ? (
                                    filteredDevices.map((device) => (
                                        <div
                                            className="mb-3 flex flex-col gap-1 border-b pb-3 last:border-0"
                                            key={device.id}
                                        >
                                            <div className="flex gap-2">
                                                <Checkbox
                                                    id={`per-device-${device.id}`}
                                                    checked={
                                                        taskDevices.find((d) => d.id === device.id) !==
                                                        undefined
                                                    }
                                                    onCheckedChange={(checked) =>
                                                        handleCheckboxChange(
                                                            device.id,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                <label
                                                    htmlFor={`per-device-${device.id}`}
                                                    className="text-sm font-medium"
                                                >
                                                    {deviceFilterLabel(device)}
                                                </label>
                                            </div>
                                            {isAssignSubscription ? (
                                                <>
                                                    <select
                                                        value={
                                                            perDeviceLicenseSelections[device.id]
                                                                ?.license_tag ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            setPerDeviceLicenseSelections((prev) => ({
                                                                ...prev,
                                                                [device.id]: {
                                                                    license_tag: e.target.value,
                                                                    license_type:
                                                                        prev[device.id]?.license_type ??
                                                                        bulkLicenseType,
                                                                },
                                                            }))
                                                        }
                                                        className={selectClassName}
                                                    >
                                                        <option value="">Select tag</option>
                                                        {availableLicenseTags.map((tag) => (
                                                            <option key={tag} value={tag}>
                                                                {tag}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <select
                                                        value={
                                                            perDeviceLicenseSelections[device.id]
                                                                ?.license_type ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            setPerDeviceLicenseSelections((prev) => ({
                                                                ...prev,
                                                                [device.id]: {
                                                                    license_tag:
                                                                        prev[device.id]?.license_tag ??
                                                                        bulkLicenseTag,
                                                                    license_type: e.target
                                                                        .value as LicenseTypeOption,
                                                                },
                                                            }))
                                                        }
                                                        className={selectClassName}
                                                    >
                                                        <option value="">Select type</option>
                                                        {filteredLicenseTypes.map((licenseType) => (
                                                            <option
                                                                key={licenseType}
                                                                value={licenseType}
                                                            >
                                                                {licenseType}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </>
                                            ) : null}
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-muted-foreground text-sm">
                                        No devices match your filters.
                                    </p>
                                )}
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="outline">Close</Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    onClick={() =>
                                        dispatch_task_with_devices(
                                            task,
                                            devices,
                                            false,
                                            'per_device',
                                        )
                                    }
                                >
                                    Deploy selected (per-device)
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                ) : null}
                {isAddToGreenLakeInventory ? (
                    <Dialog open={macModalOpen} onOpenChange={setMacModalOpen}>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="edit-mac-addresses"
                                    aria-label="Edit MAC addresses"
                                    onClick={() => {
                                        const hasSelection = taskDevices.length > 0;
                                        const selected = hasSelection
                                            ? devicesWithMac.filter((device) =>
                                                  taskDevices.some((d) => d.id === device.id),
                                              )
                                            : devicesWithMac;
                                        if (selected.length === 0) {
                                            toast.error('Select at least one device.');

                                            return;
                                        }
                                        setPendingDeployAllDevices(!hasSelection);
                                        openMacModalForDevices(selected);
                                    }}
                                >
                                    <NetworkIcon className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Edit MAC addresses</p>
                            </TooltipContent>
                        </Tooltip>
                        <DialogContent className="max-w-lg">
                            <DialogTitle>Device MAC addresses</DialogTitle>
                            <DialogDescription>
                                Enter a MAC address for each device before adding them to GreenLake
                                inventory.
                            </DialogDescription>
                            <div className="-mx-4 no-scrollbar max-h-[50vh] space-y-3 overflow-y-auto px-4">
                                {macModalDevices.map((device) => {
                                    const draft = macDrafts[device.id] ?? '';
                                    const valid =
                                        draft.trim() === '' || isValidMacAddress(draft);

                                    return (
                                        <div
                                            key={device.id}
                                            className="flex flex-col gap-1 border-b pb-3 last:border-0"
                                        >
                                            <p className="text-sm font-medium">{device.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                Serial: {String(device.serial ?? '')}
                                            </p>
                                            <Input
                                                value={draft}
                                                placeholder="aa:bb:cc:dd:ee:ff"
                                                data-test={`mac-address-input-${device.id}`}
                                                aria-invalid={!valid}
                                                className={!valid ? 'border-destructive' : undefined}
                                                onChange={(e) =>
                                                    setMacDrafts((prev) => ({
                                                        ...prev,
                                                        [device.id]: e.target.value,
                                                    }))
                                                }
                                            />
                                            {!valid ? (
                                                <p className="text-destructive text-xs">
                                                    Enter a valid MAC address (e.g. aa:bb:cc:dd:ee:ff).
                                                </p>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                            <DialogFooter className="gap-2 sm:justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={macSaving}
                                    onClick={async () => {
                                        const saved = await saveMacAddresses();
                                        if (saved) {
                                            setMacModalOpen(false);
                                        }
                                    }}
                                >
                                    Save
                                </Button>
                                <Button
                                    type="button"
                                    disabled={macSaving}
                                    data-test="save-mac-and-deploy"
                                    onClick={() => void handleSaveMacAndDeploy()}
                                >
                                    {macSaving ? 'Saving…' : 'Save & Deploy'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                ) : null}
                <Dialog open={isLaunching}>
                    {taskDevices.length > 0 &&
                    taskDevices.length < devices.length &&
                    !isAddVlansWithPrefix ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        size="icon"
                                        className="rounded-full"
                                        aria-label="Deploy selected devices"
                                        onClick={() => dispatch_task_with_devices(task, devicesWithMac)}
                                    >
                                        <BoltIcon className="size-4" aria-hidden />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>Deploy selected</p>
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        size="icon"
                                        className="rounded-full"
                                        aria-label="Deploy all devices"
                                        onClick={() => dispatch_task_with_devices(task, devicesWithMac, true)}
                                    >
                                        <BoltIcon className="size-4" aria-hidden />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>
                                        {isAddVlansWithPrefix
                                            ? 'Deploy to WHSE-prefixed groups in Central'
                                            : 'Deploy all'}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        )
                    }
                    <DialogContent
                        className="sm:max-w-sm [&>button]:hidden"
                        onInteractOutside={(event) => event.preventDefault()}
                        onEscapeKeyDown={(event) => event.preventDefault()}
                    >
                        <DialogTitle className="sr-only">Launching task</DialogTitle>
                        <div
                            className="flex flex-col items-center gap-3 py-4"
                            role="status"
                            aria-live="polite"
                            aria-busy="true"
                        >
                            <Spinner className="size-8" />
                            <p className="text-sm font-medium">
                                launching {task_friendly_name}
                            </p>
                        </div>
                    </DialogContent>
                </Dialog>
                </div>
                {isAddToGreenLakeInventory && (
                    <div className="ml-auto flex shrink-0 items-center">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="check-greenlake-inventory"
                                    aria-label="Verify devices in GreenLake inventory"
                                    disabled={isCheckingGreenLake}
                                    onClick={() => {
                                        void checkGreenLakeInventory();
                                    }}
                                >
                                    <CircleCheck className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Verify devices in GreenLake inventory</p>
                            </TooltipContent>
                        </Tooltip>
                        <Dialog
                            open={isCheckingGreenLake}
                            onOpenChange={(open) => {
                                if (!open && greenLakeCheckComplete) {
                                    closeGreenLakeInventoryCheck();
                                }
                            }}
                        >
                            <DialogContent
                                className={
                                    greenLakeCheckComplete
                                        ? 'sm:max-w-md'
                                        : 'sm:max-w-md [&>button]:hidden'
                                }
                                onInteractOutside={(e) => {
                                    if (!greenLakeCheckComplete) {
                                        e.preventDefault();
                                    }
                                }}
                                onEscapeKeyDown={(e) => {
                                    if (!greenLakeCheckComplete) {
                                        e.preventDefault();
                                    }
                                }}
                            >
                                <DialogTitle>
                                    {greenLakeCheckComplete
                                        ? 'GreenLake inventory check results'
                                        : 'Verifying GreenLake inventory'}
                                </DialogTitle>
                                <DialogDescription className="sr-only">
                                    {greenLakeCheckComplete
                                        ? 'Results of verifying deployment devices in GreenLake inventory'
                                        : 'Progress while verifying deployment devices in GreenLake inventory'}
                                </DialogDescription>
                                {!greenLakeCheckComplete ? (
                                    <div
                                        className="flex flex-col gap-3 py-2"
                                        role="status"
                                        aria-live="polite"
                                        aria-busy="true"
                                    >
                                        <div
                                            className="bg-muted h-2 w-full overflow-hidden rounded-full"
                                            role="progressbar"
                                            aria-valuenow={greenLakeProgress.percent}
                                            aria-valuemin={0}
                                            aria-valuemax={100}
                                        >
                                            <div
                                                className="bg-primary h-full rounded-full transition-all duration-300"
                                                style={{
                                                    width: `${greenLakeProgress.percent}%`,
                                                }}
                                            />
                                        </div>
                                        <p className="text-muted-foreground text-sm">
                                            {greenLakeProgress.total > 0
                                                ? `Step ${greenLakeProgress.current} of ${greenLakeProgress.total}`
                                                : 'Starting…'}
                                            {greenLakeProgress.percent > 0 &&
                                                ` (${greenLakeProgress.percent}%)`}
                                        </p>
                                        <p className="text-sm font-medium">
                                            {greenLakeProgress.message}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-3 py-2">
                                        {greenLakeCheckResult?.error ? (
                                            <p className="text-sm font-medium text-red-600 dark:text-red-400">
                                                {greenLakeCheckResult.error}
                                            </p>
                                        ) : (
                                            <>
                                                <p className="text-sm font-medium text-green-600 dark:text-green-400">
                                                    {greenLakeCheckResult?.passedCount ?? 0}{' '}
                                                    {(greenLakeCheckResult?.passedCount ?? 0) === 1
                                                        ? 'device'
                                                        : 'devices'}{' '}
                                                    passed
                                                </p>
                                                {(greenLakeCheckResult?.failedDevices.length ??
                                                    0) > 0 && (
                                                    <div className="flex flex-col gap-2">
                                                        <p className="text-sm font-medium text-red-600 dark:text-red-400">
                                                            Failed devices
                                                        </p>
                                                        <ul className="list-inside list-disc space-y-1 text-sm text-red-600 dark:text-red-400">
                                                            {greenLakeCheckResult?.failedDevices.map(
                                                                (device) => (
                                                                    <li key={device}>{device}</li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </div>
                                )}
                                {greenLakeCheckComplete && (
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={closeGreenLakeInventoryCheck}
                                        >
                                            Close
                                        </Button>
                                    </DialogFooter>
                                )}
                            </DialogContent>
                        </Dialog>
                    </div>
                )}
                {(task === 'PREPROVISION_DEVICE_TO_GROUP' ||
                    task === 'MOVE_DEVICE_TO_GROUP' ||
                    task === 'ADD_VLANS_TO_DEVICE_GROUP') && (
                    <div className="ml-auto flex shrink-0 items-center">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="check-central-groups"
                                    aria-label="Verify groups in Central"
                                    onClick={() => {
                                        router.post(check_central_group(deployment.id).url, {
                                            task_type: task,
                                            vlan_site_prefix:
                                                task === 'ADD_VLANS_TO_DEVICE_GROUP'
                                                    ? vlanPrefixTrimmed || undefined
                                                    : undefined,
                                        });
                                    }}
                                >
                                    <CircleCheck className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Verify groups in Central</p>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                )}
                {task === 'ASSOCIATE_SITE_AND_NAME' && (
                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="check-central-sites"
                                    aria-label="Verify sites in Central"
                                    onClick={() => {
                                        router.post(check_central_sites(deployment.id).url, {
                                            task_type: task,
                                        });
                                    }}
                                >
                                    <CircleCheck className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Verify sites in Central</p>
                            </TooltipContent>
                        </Tooltip>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="rounded-full"
                                    data-test="force-update-site-scope-ids"
                                    aria-label="Force update site scope IDs"
                                    onClick={() => {
                                        router.post(force_update_site_scope_ids(deployment.id).url, {
                                            task_type: task,
                                        });
                                    }}
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Force update site scope IDs</p>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                )}
                {(task === 'CONFIGURE_LAG_INTERFACE' ||
                    task === 'CONFIGURE_VLAN_INTERFACE' ||
                    task === 'CONFIGURE_ALL_INTERFACE') && (
                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        {(task === 'CONFIGURE_LAG_INTERFACE' ||
                            task === 'CONFIGURE_ALL_INTERFACE') && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        className="rounded-full"
                                        data-test="check-lag-port-lists"
                                        aria-label="Verify LAG port lists"
                                        onClick={() => {
                                            router.post(check_lag_port_lists(deployment.id).url, {
                                                task_type: task,
                                            });
                                        }}
                                    >
                                        <CircleCheck className="size-4" aria-hidden />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>Verify LAG port lists</p>
                                </TooltipContent>
                            </Tooltip>
                        )}
                        {(task === 'CONFIGURE_VLAN_INTERFACE' ||
                            task === 'CONFIGURE_ALL_INTERFACE') && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        className="rounded-full"
                                        data-test="check-vlan-ip-addresses"
                                        aria-label="Verify VLAN IP addresses"
                                        onClick={() => {
                                            router.post(check_vlan_ip_addresses(deployment.id).url, {
                                                task_type: task,
                                            });
                                        }}
                                    >
                                        <CircleCheck className="size-4" aria-hidden />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>Verify VLAN IP addresses</p>
                                </TooltipContent>
                            </Tooltip>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    )
}
