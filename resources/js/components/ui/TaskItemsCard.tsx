import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import TaskDurationDialog from '@/components/ui/TaskDurationDialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { check_central_sites, check_lag_port_lists, check_vlan_ip_addresses, force_update_site_scope_ids, store } from '@/routes/tasks';
import FilterIcon from '@/components/ui/FilterIcon';
import { TaskRequiredColumnsInfo } from '@/components/ui/TaskRequiredColumnsInfo';
import { AlarmClockIcon, BoltIcon, CircleCheck, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';

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

export default function TaskItemsCard({ task, task_friendly_name, task_friendly_description, required_columns, devices, deployment, requiresClassicCentral = false } : { task: string, task_friendly_name: string, task_friendly_description: string, required_columns: string[], devices: DeviceType[], deployment: DeploymentType, requiresClassicCentral?: boolean }) {
    const [taskDevices, setTaskDevices] = useState<DeviceType[]>([])
    const [switchesOnly, setSwitchesOnly] = useState(false)
    const [apsOnly, setAPsOnly] = useState(false)
    const [deviceSearch, setDeviceSearch] = useState('')
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0)
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(10)
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(0)
    const [overrideDeviceScope, setOverrideDeviceScope] = useState<'vsf_only' | 'all'>('vsf_only')
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

    const handleCheckboxChange = (deviceId : number, checked : boolean) => {
        const newDevice = devices.find(device => device.id === deviceId)
        if (checked) {
            setTaskDevices([...taskDevices, {...newDevice, completed: false}])
        } else {
            if (taskDevices.find(device => device.id === deviceId)) {
                setTaskDevices(taskDevices.filter(device => device.id !== deviceId))
            }
        }
    }

    const dispatch_task_with_devices = (task, devices, allDevices = false) => {
        const devices_for_task= allDevices ? devices : devices.filter(device => taskDevices.find(dev => device.id === dev.id) !== undefined)
        // const devices_with_completed_status = devices_for_task.map(device => ({...device, completed: false}))
        setTaskDevices(devices_for_task)
        const deploymentTimeTotalMinutes = deploymentTimeHours * 60 + deploymentTimeMinutes
        const taskData = {
            task_type: task,
            devices: devices_for_task,
            deployment_time: deploymentTimeTotalMinutes,
            wait_time: waitTimeMinutes,
            ...(task === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES'
                ? { override_device_scope: overrideDeviceScope }
                : {}),
        }
        router.post(store(deployment.id).url, taskData)
    }

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
                {task === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES' ? (
                    <div className="mt-3 space-y-1">
                        <label htmlFor="override-device-scope" className="text-sm font-medium">
                            Device scope
                        </label>
                        <select
                            id="override-device-scope"
                            value={overrideDeviceScope}
                            onChange={(e) =>
                                setOverrideDeviceScope(e.target.value as 'vsf_only' | 'all')
                            }
                            className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full max-w-[16rem] rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            data-test="override-device-scope"
                        >
                            <option value="vsf_only">VSF devices only (has SKU)</option>
                            <option value="all">All selected devices</option>
                        </select>
                        <p className="text-muted-foreground text-xs">
                            VSF only clears overrides on devices with a SKU. All devices runs on every
                            selected device regardless of SKU.
                        </p>
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
                                    <Button variant="outline" size="sm" onClick={() => setSwitchesOnly(!switchesOnly)}>{switchesOnly ? 'All' : 'Switches'}</Button>
                                    <Button variant="outline" size="sm" onClick={() => setAPsOnly(!apsOnly)}>{apsOnly ? 'All' : 'APs'}</Button>
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
                                                id={`task-device-${device.id}`}
                                                checked={taskDevices.find(dev => dev.id === device.id) !== undefined}
                                                onCheckedChange={(checked) =>
                                                    handleCheckboxChange(device.id, checked === true)
                                                }
                                            />
                                            <label htmlFor={`task-device-${device.id}`}>{device.name} {device.serial ? `(${device.serial})` : ''}</label>
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
                {
                    taskDevices.length > 0 && taskDevices.length < devices.length ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    className="rounded-full"
                                    aria-label="Deploy selected devices"
                                    onClick={() => dispatch_task_with_devices(task, devices)}
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
                                    onClick={() => dispatch_task_with_devices(task, devices, true)}
                                >
                                    <BoltIcon className="size-4" aria-hidden />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p>Deploy all</p>
                            </TooltipContent>
                        </Tooltip>
                    )
                }
                </div>
                {task === 'ASSOCIATE_DEVICE_TO_SITE' && (
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
