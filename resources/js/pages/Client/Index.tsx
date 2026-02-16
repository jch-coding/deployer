import { Head, usePage } from '@inertiajs/react';
import ClientCard from '@/components/ui/ClientCard';
import CreateClientModal from '@/components/ui/CreateClientModal';
import {
    Pagination,
    PaginationContent,
    PaginationItem, PaginationLink, PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index } from '@/routes/clients';
import type { BreadcrumbItem, SharedData } from '@/types';
import { type Client } from '@/types/clients/client';
import { type Paginator } from '@/types/deployer';

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
            <CreateClientModal errors={ errors } base_urls={ base_urls } />
            <div className="m-3 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 max-w-6xl mx-auto">
                {
                    clients.length > 0 ?
                    (
                        <>
                        {
                        clients.map( client => (
                            <ClientCard
                                client={ client }
                                errors={ errors }
                                base_urls={ base_urls }
                                isCurrentClient={client.id === current_client?.id}
                            />
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
