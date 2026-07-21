import {
    ActivityIcon,
    BookOpen,
    Braces,
    BuildingIcon,
    FileUp,
    ListTodoIcon,
    MapPin,
    Radio,
    ScrollText,
    Webhook,
} from 'lucide-react';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { NavFooter } from '@/components/nav-footer';
import { NavClient } from '@/components/nav-client';
import { NavLicensing } from '@/components/nav-licensing';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { documentation, usage } from '@/routes';
import { index as client_index } from '@/routes/clients'
import { index as deployment_index } from '@/routes/deployments'
import { index as sites_index } from '@/routes/sites'
import { index as central_api_index } from '@/routes/central-api';
import { index as migrations_index } from '@/routes/migrations';
import { index as streaming_index } from '@/routes/streaming';
import { index as task_index } from '@/routes/tasks'
import { index as webhooks_index } from '@/routes/webhooks'
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
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
    {
        title: 'Sites',
        href: sites_index(),
        icon: MapPin,
    },
    {
        title: 'Tasks',
        href: task_index(),
        icon: ListTodoIcon,
    },
    {
        title: 'Central API',
        href: central_api_index(),
        icon: Braces,
    },
    {
        title: 'Webhook',
        href: webhooks_index(),
        icon: Webhook,
    },
    {
        title: 'WebSocket',
        href: streaming_index(),
        icon: Radio,
    },
    {
        title: 'Migrations',
        href: migrations_index(),
        icon: FileUp,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Usage',
        href: usage(),
        icon: ScrollText,
    },
    {
        title: 'CSV columns',
        href: documentation(),
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <NavClient />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <div className="px-2 py-0">
                    <NavLicensing />
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <AppearanceToggle />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
