import { Form, Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Field, FieldGroup } from '@/components/ui/field';
import {
    Pagination,
    PaginationContent,
    PaginationItem, PaginationLink, PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index, edit, destroy } from '@/routes/clients';
import { current } from '@/routes/clients/edit';
import type { BreadcrumbItem, SharedData } from '@/types';
import { type Client } from '@/types/clients/client';
import { type Paginator } from '@/types/deployer';
import { ButtonModal } from '@/components/ui/ButtonModal';
import { ClientForm } from '@/components/ui/ClientForm';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'Clients',
        href: index().url,
    },
];

type ClientPageProps = {
    paginator: Paginator<Client>;
    base_urls: string[];
} & SharedData

export default function Index() {
    const clientsPaginator = usePage<ClientPageProps>().props.clients as Paginator<Client>
    const clients = clientsPaginator.data
    const links = clientsPaginator.links
    const base_urls = usePage<ClientPageProps>().props.base_urls
    const current_client = usePage<ClientPageProps>().props.current_client
    const errors = usePage<ClientPageProps>().props.errors

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clients" />
            <ButtonModal
                dialogTriggerText="Add New Client"
                dialogTitle="Add New Client"
                dialogDescription="Create a new client with the following fields."
            >
                <ClientForm
                    successMessage="Client created successfully"
                    formMethod="POST"
                    submitText="Add Client"
                    errors={errors}
                    base_urls={base_urls}
                    />
            </ButtonModal>
            <div className="m-3 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 max-w-6xl mx-auto">
                {
                    clients.length > 0 ?
                        (
                    <>
                        {
                    clients.map( client => (
                                <Card key={client.client_id} className={client.id == current_client?.id ? "border-green-500" : ""}>
                                    <CardHeader>
                                        <CardTitle className="mx-auto">
                                            {client.name}
                                        </CardTitle>
                                        <CardContent className="mx-auto mt-3 flex justify-items-center gap-2">
                                            <Button onClick={() => router.put(current.url(client.id))} disabled={current_client?.id == client.id}>
                                                Set Current
                                            </Button>
                                            <ButtonModal dialogTriggerText="Edit" dialogTitle={`Edit ${client.name}`} dialogDescription="Change any of the fields shown.">
                                                <ClientForm
                                                    client={client}
                                                    successMessage="Client updated successfully"
                                                    formMethod="PUT"
                                                    submitText="Edit"
                                                    errors={errors}
                                                    base_urls={base_urls}
                                                />
                                            </ButtonModal>
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="destructive"
                                                        className="hover:bg-red-400"
                                                    >
                                                        Delete
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Delete Client?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            This action cannot be
                                                            undone. Are you sure you
                                                            want to delete this
                                                            client?
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <DialogFooter>
                                                        <DialogClose asChild>
                                                            <Button variant="secondary">
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            variant="destructive"
                                                            onClick={() => {
                                                                router.delete(
                                                                    destroy.url(client.id),
                                                                );
                                                            }}
                                                        >
                                                            Delete
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        </CardContent>
                                    </CardHeader>
                                </Card>
                            )
                    )
                        }
                    </>
                        )
                    :
                    <p>No clients found</p>
                }
            </div>
                {clients?.length > 0 && clientsPaginator.total > clientsPaginator.per_page && (
                    <Pagination className="mt-3">
                        <PaginationContent>
                            {clientsPaginator.prev_page_url && (
                                <PaginationItem>
                                    <PaginationPrevious
                                        href={
                                            clientsPaginator.prev_page_url
                                        }
                                    />
                                </PaginationItem>
                            )}
                            {links.filter((_,idx) => idx > 0 && idx < links.length - 1 ).map((link) => (
                                <PaginationItem>
                                    <PaginationLink
                                        href={link.url}
                                        isActive={link.active}
                                    >
                                        {link.label}
                                    </PaginationLink>
                                </PaginationItem>
                            ))}
                            {clientsPaginator.next_page_url && (
                                <PaginationItem>
                                    <PaginationNext
                                        href={
                                            clientsPaginator.next_page_url
                                        }
                                    />
                                </PaginationItem>
                            )}
                        </PaginationContent>
                    </Pagination>
                )}
        </AppLayout>
    );
}
