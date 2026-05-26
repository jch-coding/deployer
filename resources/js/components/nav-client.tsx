import { Link, router, usePage } from '@inertiajs/react';
import { BuildingIcon, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { index as clientIndex, current } from '@/routes/clients';
import type { SharedData } from '@/types';

export function NavClient() {
    const { clients, current_client } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const triggerLabel = current_client?.name ?? 'Client Not Set';

    if (!clients.length) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" asChild tooltip={triggerLabel}>
                        <Link
                            href={clientIndex()}
                            className="text-slate-800 font-bold dark:text-slate-200"
                            prefetch
                        >
                            <BuildingIcon className="size-4" />
                            <span>{triggerLabel}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={triggerLabel}
                            className="text-slate-800 font-bold dark:text-slate-200"
                        >
                            <BuildingIcon className="size-4" />
                            <span>{triggerLabel}</span>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        {clients.map((client) => {
                            const isCurrent = client.id === current_client?.id;
                            return (
                                <DropdownMenuCheckboxItem
                                    key={client.id}
                                    checked={isCurrent}
                                    disabled={isCurrent}
                                    onSelect={() => {
                                        if (isCurrent) return;
                                        router.put(current(client.id), {}, {
                                            onSuccess: () => {
                                                router.flushAll();
                                            },
                                        });
                                    }}
                                >
                                    {client.name}
                                </DropdownMenuCheckboxItem>
                            );
                        })}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={clientIndex()} prefetch>
                                Manage clients
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

