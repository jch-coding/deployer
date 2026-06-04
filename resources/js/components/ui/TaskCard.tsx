import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import TaskDurationDialog from '@/components/ui/TaskDurationDialog';
import LicenseSelect, {
    type AvailableSubscription,
    filterSubscriptionsByDeviceCategory,
} from '@/components/licensing/LicenseSelect';
import RenewLicensingButton from '@/components/licensing/RenewLicensingButton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { check_central_group, check_central_sites, force_update_site_scope_ids, store } from '@/routes/tasks';
import FilterIcon from '@/components/ui/FilterIcon';
import { TaskRequiredColumnsInfo } from '@/components/ui/TaskRequiredColumnsInfo';
import { AlarmClockIcon, BoltIcon, CircleCheck, ListIcon, RefreshCw } from 'lucide-react';

type DeviceType = {
    id: number;
    name: string;
    completed: boolean;
    device_function: string;
    serial?: string | number;
};

type DeploymentType = {
    id: number,
    name: string,
}

type TaskCardProps = {
    task: string;
    task_friendly_name: string;
    task_friendly_description: string;
    required_columns: string[];
    devices: DeviceType[];
    deployment: DeploymentType;
    requiresClassicCentral?: boolean;
    available_subscriptions?: AvailableSubscription[];
    enabled_services?: string[];
    licensing_synced_at?: string | null;
};

export default function TaskCard({
    task,
    task_friendly_name,
    task_friendly_description,
    required_columns,
    devices,
    deployment,
    requiresClassicCentral = false,
    available_subscriptions = [],
    enabled_services = [],
    licensing_synced_at = null,
}: TaskCardProps) {
    const [taskDevices, setTaskDevices] = useState<DeviceType[]>([])
    const [completedDevices, setCompletedDevices] = useState<DeviceType[]>([])
    const [statusMessage, setStatusMessage] = useState()
    const [switchesOnly, setSwitchesOnly] = useState(false)
    const [apsOnly, setAPsOnly] = useState(false)
    const [deviceSearch, setDeviceSearch] = useState('')

    const filteredDevices = useMemo(() => {
        const q = deviceSearch.trim().toLowerCase();
        return devices.filter((device) => {
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
    }, [devices, switchesOnly, apsOnly, deviceSearch]);
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0)
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(0)
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(0)
    const [vlanSitePrefix, setVlanSitePrefix] = useState('')
    const [bulkSubscriptionKey, setBulkSubscriptionKey] = useState(
        available_subscriptions[0]?.subscription_key ?? '',
    );
    const [bulkUnassignService, setBulkUnassignService] = useState(enabled_services[0] ?? '');
    const [perDeviceSubscriptionKeys, setPerDeviceSubscriptionKeys] = useState<Record<number, string>>({});

    const isAssignSubscription = task === 'ASSIGN_SUBSCRIPTION';
    const isUnassignSubscription = task === 'UNASSIGN_SUBSCRIPTION';
    const isLicensingTask = isAssignSubscription || isUnassignSubscription;

    const filteredSubscriptions = useMemo(
        () => filterSubscriptionsByDeviceCategory(available_subscriptions, switchesOnly, apsOnly),
        [available_subscriptions, switchesOnly, apsOnly],
    );

    useEffect(() => {
        if (
            filteredSubscriptions.length > 0 &&
            !filteredSubscriptions.some((s) => s.subscription_key === bulkSubscriptionKey)
        ) {
            setBulkSubscriptionKey(filteredSubscriptions[0].subscription_key);
        }
    }, [filteredSubscriptions, bulkSubscriptionKey]);

    const vlanPrefixTrimmed = vlanSitePrefix.trim()
    const isAddVlansWithPrefix =
        task === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== ''

    const handleCheckboxChange = (deviceId : number, checked : boolean) => {
        const newDevice = devices.find(device => device.id === deviceId)
        if (checked && newDevice) {
            setTaskDevices([...taskDevices, { ...newDevice, completed: false }])
        } else {
            if (taskDevices.find(device => device.id === deviceId)) {
                setTaskDevices(taskDevices.filter(device => device.id !== deviceId))
            }
        }
    }

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
    ) => {
        const devices_for_task = resolveDevicesForDispatch(devicesList, allDevices);

        if (devices_for_task.length === 0 && !(taskStr === 'ADD_VLANS_TO_DEVICE_GROUP' && vlanPrefixTrimmed !== '')) {
            toast.error('Select at least one device.');

            return;
        }

        if (taskStr === 'ASSIGN_SUBSCRIPTION') {
            if (licensingMode === 'uniform' && !bulkSubscriptionKey) {
                toast.error('Select a license to assign.');

                return;
            }
            const bulkSub = filteredSubscriptions.find((s) => s.subscription_key === bulkSubscriptionKey);
            if (
                licensingMode === 'uniform' &&
                bulkSub &&
                devices_for_task.length > bulkSub.available
            ) {
                toast.error(`Only ${bulkSub.available} seat(s) available on this license.`);

                return;
            }
            if (licensingMode === 'per_device') {
                const missing = devices_for_task.some(
                    (d) => !(perDeviceSubscriptionKeys[d.id] ?? '').trim(),
                );
                if (missing) {
                    toast.error('Select a license for each device in the modal.');

                    return;
                }
            }
        }

        if (taskStr === 'UNASSIGN_SUBSCRIPTION') {
            if (licensingMode === 'uniform' && !bulkUnassignService) {
                toast.error('Select a service to unassign.');

                return;
            }
            if (licensingMode === 'per_device') {
                const missing = devices_for_task.some(
                    (d) => !(perDeviceSubscriptionKeys[d.id] ?? '').trim(),
                );
                if (missing) {
                    toast.error('Select a service to remove for each device in the modal.');

                    return;
                }
            }
        }

        setTaskDevices(devices_for_task);
        const deploymentTimeTotalMinutes = deploymentTimeHours * 60 + deploymentTimeMinutes;

        const devicePayload = devices_for_task.map((device) => {
            if (taskStr === 'ASSIGN_SUBSCRIPTION' && licensingMode === 'per_device') {
                return {
                    id: device.id,
                    subscription_key: perDeviceSubscriptionKeys[device.id],
                };
            }
            if (taskStr === 'UNASSIGN_SUBSCRIPTION' && licensingMode === 'per_device') {
                return {
                    id: device.id,
                    service_name: perDeviceSubscriptionKeys[device.id],
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
            subscription_key:
                taskStr === 'ASSIGN_SUBSCRIPTION' && licensingMode === 'uniform'
                    ? bulkSubscriptionKey
                    : undefined,
            service_name:
                taskStr === 'UNASSIGN_SUBSCRIPTION' && licensingMode === 'uniform'
                    ? bulkUnassignService
                    : undefined,
            ...(taskStr === 'ADD_VLANS_TO_DEVICE_GROUP'
                ? { vlan_site_prefix: vlanPrefixTrimmed }
                : {}),
        });
    };

    const resetCompletedDevices = () => setCompletedDevices([])

    return (
        <Card className="w-96">
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
                        <label htmlFor={`bulk-license-${task}`} className="text-sm font-medium">
                            License for selected devices
                        </label>
                        <LicenseSelect
                            id={`bulk-license-${task}`}
                            value={bulkSubscriptionKey}
                            subscriptions={filteredSubscriptions}
                            onChange={setBulkSubscriptionKey}
                        />
                        <p className="text-muted-foreground text-xs">
                            Applies to all devices when deploying. Use per-device modal for mixed
                            licenses. Central assigns a service tier from this pool.
                        </p>
                    </div>
                ) : null}
                {isUnassignSubscription ? (
                    <div className="mt-3 space-y-1">
                        <label htmlFor={`bulk-unassign-${task}`} className="text-sm font-medium">
                            Service to remove
                        </label>
                        <select
                            id={`bulk-unassign-${task}`}
                            value={bulkUnassignService}
                            onChange={(e) => setBulkUnassignService(e.target.value)}
                            className="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                        >
                            {enabled_services.map((service) => (
                                <option key={service} value={service}>
                                    {service}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className="flex w-full flex-wrap items-center gap-2">
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
                                            <label htmlFor={`task-card-device-${device.id}`}>{device.name}</label>
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
                                Assign a different license or service to each device, then deploy
                                selected.
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
                                                    {device.name}
                                                </label>
                                            </div>
                                            {isAssignSubscription ? (
                                                <LicenseSelect
                                                    value={perDeviceSubscriptionKeys[device.id] ?? ''}
                                                    subscriptions={filteredSubscriptions}
                                                    onChange={(key) =>
                                                        setPerDeviceSubscriptionKeys((prev) => ({
                                                            ...prev,
                                                            [device.id]: key,
                                                        }))
                                                    }
                                                    placeholder="License for this device"
                                                />
                                            ) : (
                                                <select
                                                    value={perDeviceSubscriptionKeys[device.id] ?? ''}
                                                    onChange={(e) =>
                                                        setPerDeviceSubscriptionKeys((prev) => ({
                                                            ...prev,
                                                            [device.id]: e.target.value,
                                                        }))
                                                    }
                                                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                                >
                                                    <option value="">Select service</option>
                                                    {enabled_services.map((service) => (
                                                        <option key={service} value={service}>
                                                            {service}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
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
                <Dialog>
                    {taskDevices.length > 0 &&
                    taskDevices.length < devices.length &&
                    !isAddVlansWithPrefix ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <DialogTrigger asChild>
                                        <Button
                                            type="button"
                                            size="icon"
                                            className="rounded-full"
                                            aria-label="Deploy selected devices"
                                            onClick={() => dispatch_task_with_devices(task, devices)}
                                        >
                                            <BoltIcon className="size-4" aria-hidden />
                                        </Button>
                                    </DialogTrigger>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p>Deploy selected</p>
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <DialogTrigger asChild>
                                        <Button
                                            type="button"
                                            size="icon"
                                            className="rounded-full"
                                            aria-label="Deploy all devices"
                                            onClick={() => dispatch_task_with_devices(task, devices, true)}
                                        >
                                            <BoltIcon className="size-4" aria-hidden />
                                        </Button>
                                    </DialogTrigger>
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
                    <DialogContent>
                        <DialogTitle>{task} Progress</DialogTitle>
                        <DialogDescription>
                            {completedDevices.length} / {taskDevices.length} {statusMessage}
                        </DialogDescription>
                        <DialogClose asChild>
                            <Button onClick={() => resetCompletedDevices()}>Close</Button>
                        </DialogClose>
                        <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                            <ul>
                                {
                                    completedDevices.length > 0 ?
                                        completedDevices.map((device, index) =>
                                            <li key={index} className='text-emerald-500'>{ device ? device.name : device }</li>
                                        ) : <li>Deployment started</li>
                                }
                            </ul>
                        </div>
                    </DialogContent>
                </Dialog>
                </div>
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
            </CardContent>
        </Card>
    )
}
