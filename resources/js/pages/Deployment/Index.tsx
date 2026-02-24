import { Form, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
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
import { Input } from '@/components/ui/input';
import { store } from '@/actions/App/Http/Controllers/DeploymentController';
import { useEffect, useRef, useState } from 'react';
import { FieldGroup } from '@/components/ui/field';
import { Field } from '@headlessui/react';
import { columns } from '@/components/ui/columns';
import { DataTable } from '@/components/ui/data-table';

type Deployment = {
    name: string;
    devices: number;
}

type DeploymentIndexProps = {
    deployments: Deployment[];
} & SharedData;

export default function Index() {
    const deployments = usePage<DeploymentIndexProps>().props.deployments;
    const dialogCloseRef = useRef(null);
    const [success, setSuccess] = useState(false);
    useEffect(() => {
        if (!success) return;
        dialogCloseRef.current?.click();
    })
    return (
        <AppLayout>
            <div className="min-w-6xl mx-auto flex-1">
                <h1 className="font-bold text-2xl text-center">Deployments</h1>
                {
                    deployments.length > 0
                        ? <DataTable data={deployments} columns={columns} />
                        : <p>No deployments found</p>
                }
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            data-test="add-deployment-trigger"
                            className="absolute right-3 top-3"
                        >
                            Add Deployment
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Add Deployment</DialogTitle>
                        <DialogDescription>
                            Add a new deployment
                        </DialogDescription>
                        <Form
                            action={store()}
                            method="POST"
                            className="p-3"
                            onSuccess={() => setSuccess(true)}
                        >
                            <FieldGroup>
                            <Field>
                                <label htmlFor="name">Name</label>
                                <Input
                                    type="text"
                                    name="name"
                                    placeholder="Deployment Name"
                                    className="mt-2"
                                    required
                                />
                            </Field>
                            <Field>
                                <label htmlFor="description">Description</label>
                                <textarea
                                    name="description"
                                    placeholder="Add Description"
                                    className="mt-3 w-full h-1/2"
                                />
                            </Field>
                            </FieldGroup>
                            <DialogFooter className="flex justify-end gap-2 mt-4">
                                <Button data-test="add-deployment" type="submit">
                                    Add Deployment
                                </Button>
                                <DialogClose asChild>
                                    <Button ref={dialogCloseRef}>Cancel</Button>
                                </DialogClose>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
