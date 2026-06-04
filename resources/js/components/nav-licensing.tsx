import { Link, router } from '@inertiajs/react';
import { ChevronRight, KeyRound, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as licensingIndex, renew } from '@/routes/licensing';

export function NavLicensing() {
    const { isCurrentUrl } = useCurrentUrl();
    const licensingActive = isCurrentUrl(licensingIndex());
    const [open, setOpen] = useState(licensingActive);
    const [isRenewing, setIsRenewing] = useState(false);

    const handleRenew = () => {
        setIsRenewing(true);
        router.post(
            renew.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsRenewing(false),
            },
        );
    };

    return (
        <SidebarMenu>
            <Collapsible open={open} onOpenChange={setOpen} className="group/collapsible">
                <SidebarMenuItem>
                    <CollapsibleTrigger asChild>
                        <SidebarMenuButton
                            tooltip={{ children: 'Licensing' }}
                            isActive={licensingActive}
                        >
                            <KeyRound />
                            <span>Licensing</span>
                            <ChevronRight className="ml-auto size-4 transition-transform group-data-[state=open]/collapsible:rotate-90" />
                        </SidebarMenuButton>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <SidebarMenuSub>
                            <SidebarMenuSubItem>
                                <SidebarMenuSubButton asChild isActive={licensingActive}>
                                    <Link href={licensingIndex()} prefetch>
                                        <span>Inventory</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                            <SidebarMenuSubItem>
                                <SidebarMenuSubButton
                                    onClick={handleRenew}
                                    disabled={isRenewing}
                                    data-test="sidebar-renew-licensing"
                                >
                                    <RefreshCw
                                        className={`size-4 ${isRenewing ? 'animate-spin' : ''}`}
                                    />
                                    <span>{isRenewing ? 'Renewing…' : 'Renew from Central'}</span>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        </SidebarMenuSub>
                    </CollapsibleContent>
                </SidebarMenuItem>
            </Collapsible>
        </SidebarMenu>
    );
}
