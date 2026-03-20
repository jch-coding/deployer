import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { store } from '@/routes/tasks';
import FilterIcon from '@/components/ui/FilterIcon';
import { AlarmClockIcon,
    BoltIcon} from 'lucide-react';

type DeviceType = {
    id: number,
    name: string,
    completed: boolean
}

type DeploymentType = {
    id: number,
    name: string,
}

export default function TaskItemsCard({ task, devices, deployment } : { task: string, devices: DeviceType[], deployment: DeploymentType }) {
    const [taskDevices, setTaskDevices] = useState<DeviceType[]>([])
    // const [completedDevices, setCompletedDevices] = useState<DeviceType[]>([])
    const [completedItems, setCompletedItems] = useState<string[]>([])
    const [statusMessage, setStatusMessage] = useState()
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(0)
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(0)
    const items = task in usePage().props.items ? usePage().props.items[task] : []
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
        }
        router.post(store(deployment.id).url, taskData)
    }

    const resetCompletedItems = () => setCompletedItems([])

    const newItemUpdated = (newItemEvent) => {
        if (newItemEvent.event_type === 'FailureEvent') {

        }
        const newItem = newItemEvent.data.item_name
        setCompletedItems((prevState) => [...prevState, newItem])
        // const newDeviceUpdated = {...devices.find(device => device.id === parseInt(newItemEvent.data.device_name)), completed: true}
        // setCompletedDevices((prevState) => [...prevState, newDeviceUpdated])

        setStatusMessage(newItemEvent.data.message)
    }

    useEcho(
        `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
        ['DeploymentEvent','FailureEvent'],
        (event) => {
            newItemUpdated(event)
    })

    return (
        <Card className="min-w-sm">
            <CardHeader>
                <CardTitle>{task}</CardTitle>
                <CardDescription>
                    {taskDevices.length > 0 && taskDevices.length < devices.length ? `${taskDevices.length} devices selected` : 'All Devices Selected'}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex gap-2">
                <Dialog>
                    <DialogTrigger asChild>
                        <Button data-test="set-deployment-time"><AlarmClockIcon/>Set Duration</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Set Deployment Duration</DialogTitle>
                        <DialogDescription>
                            Set the duration of the deployment
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
                    <DialogTrigger asChild>
                        <Button data-test="associate-devices-with-task" aria-description="Filter Devices">
                            <FilterIcon/>
                            Filter</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Associate Devices</DialogTitle>
                        <DialogDescription>
                            Filter devices associated with this task
                        </DialogDescription>
                            <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                        {
                            devices.length > 0 ?
                                devices.map((device, index) =>
                                    <div className="flex gap-2" key={index}>
                                        <Checkbox
                                            id={`device-${index}`}
                                            checked={taskDevices.find(dev => dev.id === device.id) !== undefined}
                                            onCheckedChange={(checked) => handleCheckboxChange(device.id, checked)}
                                        />
                                        <label htmlFor={`device-${index}`}>{device.name}</label>
                                    </div>
                                ) :
                                <p>Add devices to deployment before adding tasks</p>
                        }
                            </div>
                    </DialogContent>
                </Dialog>
                <Dialog>
                    <DialogTrigger asChild>
                        {
                            taskDevices.length > 0 && taskDevices.length < devices.length ?
                                <Button onClick={() => dispatch_task_with_devices(task, devices)}><BoltIcon/>Deploy Selected</Button> :
                                <Button onClick={() => dispatch_task_with_devices(task, devices, true)}><BoltIcon/>Deploy All</Button>
                        }
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{task} Progress</DialogTitle>
                        <DialogDescription>
                            {completedItems.length} / {items.length} {statusMessage}
                        </DialogDescription>
                        <DialogClose asChild>
                            <Button onClick={() => resetCompletedItems()}>Close</Button>
                        </DialogClose>
                        <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                            <ul>
                                {
                                    completedItems.length > 0 ?
                                        completedItems.map((item, index) =>
                                            <li key={index} className='text-emerald-500'>{  item }</li>
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
