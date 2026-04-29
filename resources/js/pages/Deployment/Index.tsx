import { Field } from '@headlessui/react';
import { Form, Link, router, usePage } from '@inertiajs/react';
import { TrashIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { store } from '@/actions/App/Http/Controllers/DeploymentController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { destroy, show as showDeployment } from '@/routes/deployments';
import type { BreadcrumbItem, SharedData } from '@/types';

type Deployment = {
    id: number;
    name: string;
    devices_count: number;
}

type DeploymentIndexProps = {
    deployments: Deployment[];
} & SharedData;

export default function Index() {
    const { deployments, current_client } = usePage<DeploymentIndexProps>().props;
    const dialogCloseRef = useRef<HTMLButtonElement | null>(null);
    const [success, setSuccess] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
    ];
    useEffect(() => {
        if (!success) return;
        dialogCloseRef.current?.click();
    })
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="min-w-6xl mx-auto flex-1">
                <h1 className="font-bold text-2xl text-center">Deployments</h1>
                {deployments.length > 0 ? (
                    <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {deployments.map((deployment) => (
                            <Card key={deployment.id}>
                                <CardHeader>
                                    <CardTitle>{deployment.name}</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    <p className="text-muted-foreground text-sm">
                                        {deployment.devices_count === 1
                                            ? '1 device'
                                            : `${deployment.devices_count} devices`}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={showDeployment(deployment.id).url}
                                            className="text-primary text-sm font-medium hover:underline"
                                            data-test="deployment-link"
                                        >
                                            View deployment
                                        </Link>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-auto px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            data-test="delete"
                                            onClick={() =>
                                                router.delete(destroy(deployment.id))
                                            }
                                        >
                                            <TrashIcon
                                                className="mr-1 size-4"
                                                aria-hidden
                                            />
                                            Delete
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                ) : (
                    <p>No deployments found</p>
                )}
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
