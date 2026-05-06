import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { check_central_group, store } from '@/routes/tasks';
import FilterIcon from '@/components/ui/FilterIcon';
import { TaskRequiredColumnsInfo } from '@/components/ui/TaskRequiredColumnsInfo';
import { AlarmClockIcon, BoltIcon, CircleCheck } from 'lucide-react';

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

export default function TaskCard({ task, task_friendly_name, task_friendly_description, required_columns, devices, deployment, requiresClassicCentral = false } : { task: string, task_friendly_name: string, task_friendly_description: string, required_columns: string[], devices: DeviceType[], deployment: DeploymentType, requiresClassicCentral?: boolean }) {
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
        }
        router.post(store(deployment.id).url, taskData)
    }

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
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
                <Dialog>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <DialogTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    className="rounded-full"
                                    data-test="set-deployment-time"
                                    aria-label="Set duration"
                                >
                                    <AlarmClockIcon className="size-4" aria-hidden />
                                </Button>
                            </DialogTrigger>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <p>Set duration</p>
                        </TooltipContent>
                    </Tooltip>
                    <DialogContent>
                        <DialogTitle>Set Task Duration</DialogTitle>
                        <DialogDescription>
                            Set the duration of the task
                        </DialogDescription>
                        <div className="flex gap-2">
                            <label htmlFor="deployment-time-hours" className="self-center">Hours</label>
                            <input
                                type="number"
                                value={deploymentTimeHours}
                                onChange={(e) => setDeploymentTimeHours(parseInt(e.target.value))}
                                className="w-1/4 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <label htmlFor="deployment-time-minutes" className="self-center">Minutes</label>
                            <input
                                type="number"
                                value={deploymentTimeMinutes}
                                onChange={(e) => setDeploymentTimeMinutes(parseInt(e.target.value))}
                                className="w-1/4 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>
                        <div>
                            <label htmlFor="wait-time-minutes" className="self-center pr-2">Retry Interval</label>
                            <input
                                type="number"
                                value={waitTimeMinutes}
                                onChange={(e) => setWaitTimeMinutes(parseInt(e.target.value))}
                                className="w-1/4 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <i className="text-slate-400 pl-2">in minutes</i>
                        </div>
                        <DialogFooter className="sm:justify-start">
                            <DialogClose asChild>
                                <Button className="hover:bg-slate-300">
                                    Set Duration
                                </Button>
                            </DialogClose>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
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
                {(task === 'PREPROVISION_DEVICE_TO_GROUP' || task === 'MOVE_DEVICE_TO_GROUP') && (
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
                )}
                <Dialog>
                    {
                        taskDevices.length > 0 && taskDevices.length < devices.length ? (
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
                                    <p>Deploy all</p>
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
            </CardContent>
        </Card>
    )
}
