import { Link, usePage } from '@inertiajs/react';
import {
    ActivityIcon,
    BookOpen,
    BuildingIcon,
    LayoutGrid,
} from 'lucide-react';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, documentation } from '@/routes';
import { index as client_index } from '@/routes/clients'
import { index as deployment_index } from '@/routes/deployments'
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Clients',
        href: client_index(),
        icon: BuildingIcon,
    },
    {
        title: 'Deployments',
        href: deployment_index(),
        icon: ActivityIcon,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        href: documentation(),
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const current_client = usePage().props.current_client;
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} className="text-slate-800 dark:text-slate-200 font-bold" prefetch>
                                {current_client ? current_client.name : 'Client Not Set'}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
