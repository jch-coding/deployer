import { Form, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { storeMany } from '@/actions/App/Http/Controllers/DeviceController';
import { useEffect, useState, useRef } from 'react';
import { columns } from '@/components/ui/devices-columns';
import { DataTable } from '@/components/ui/data-table';
import { toast } from 'sonner';
export default function Show() {
    const deployment = usePage().props.deployment;
    const devices = deployment.devices;
    const { data, setData, post, progress, errors } = useForm({
        devices: null,
    })

    const [submitting, setSubmitting] = useState(false)
    const closeTriggerRef = useRef(null)
    function handleSubmit(e ) {
        e.preventDefault()
        post(storeMany(deployment.id).url)
    }

    useEffect(() => {
        if (!submitting) return;
        closeTriggerRef.current?.click()
    })

    return (
        <AppLayout>
            <h1 className="text-3xl font-semibold text-center">{deployment.name}</h1>
            <div className="grid grid-cols-3 gap-4 mt-4 p-4">
                <div>
                    {devices.length > 0 ?
                        <DataTable data={devices} columns={columns} />
                     :
                        <p>No devices assigned to this deployment</p>
                    }
                </div>
                <div>
                    <p>Tasks</p>
                </div>
                <div>Task Status</div>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button data-test="add-devices" className="absolute top-4 right-4">
                            Add Devices
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Add Device</DialogTitle>
                        <DialogDescription>
                            Add devices to this deployment
                        </DialogDescription>
                        <Form
                            action={storeMany(deployment.id).url}
                            method="POST"
                            onSuccess={() => {toast.success('Devices added successfully'); setSubmitting(false)}}
                            onError={() => {toast.error('Failed to add devices'); setSubmitting(false)}}
                            data-test="add-devices-form"
                            className="flex flex-col gap-4"
                            as="form"
                            encType="multipart/form-data"
                            onSubmit={(e) => {
                                e.preventDefault();
                                setSubmitting(true);
                                handleSubmit(e);
                            }}
                        >
                            <input
                                type="file"
                                name="devices"
                                onChange={(e) =>
                                    setData('devices', e.target.files[0])
                                }
                                className="block cursor-pointer rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400 dark:placeholder-gray-400"
                            />
                            {errors && (
                                <p className="text-xs text-red-500">
                                    {errors.devices}
                                </p>
                            )}
                            <DialogFooter className="mt-4 flex-row-reverse sm:justify-start">
                                <Button data-test="upload-devices" type="submit">Add Devices</Button>
                                {progress && (
                                    <progress
                                        value={progress.percentage}
                                        max="100"
                                    >
                                        {progress.percentage}%
                                    </progress>
                                )}
                                <DialogClose asChild>
                                    <Button
                                        className="hidden"
                                        ref={closeTriggerRef}
                                    >
                                        Close
                                    </Button>
                                </DialogClose>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
