import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const options: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

type AppearanceToggleProps = {
    variant?: 'sidebar' | 'inline';
    className?: string;
};

export function AppearanceToggle({
    variant = 'sidebar',
    className,
}: AppearanceToggleProps) {
    const { appearance, resolvedAppearance, updateAppearance } = useAppearance();

    const ActiveIcon =
        appearance === 'system'
            ? Monitor
            : resolvedAppearance === 'dark'
              ? Moon
              : Sun;

    const triggerLabel =
        options.find((option) => option.value === appearance)?.label ?? 'Theme';

    if (variant === 'inline') {
        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className={cn(
                            'inline-flex size-9 items-center justify-center rounded-md border border-border bg-background text-foreground shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground',
                            className,
                        )}
                        aria-label={`Theme: ${triggerLabel}`}
                    >
                        <ActiveIcon className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {options.map(({ value, icon: Icon, label }) => (
                        <DropdownMenuItem
                            key={value}
                            onClick={() => updateAppearance(value)}
                            className={cn(appearance === value && 'bg-accent')}
                        >
                            <Icon className="mr-2 size-4" />
                            {label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        );
    }

    return (
        <SidebarMenu className={className}>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            tooltip={`Theme: ${triggerLabel}`}
                            className="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                        >
                            <ActiveIcon className="size-4" />
                            <span>Theme</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="end"
                        className="min-w-36"
                    >
                        {options.map(({ value, icon: Icon, label }) => (
                            <DropdownMenuItem
                                key={value}
                                onClick={() => updateAppearance(value)}
                                className={cn(appearance === value && 'bg-accent')}
                            >
                                <Icon className="mr-2 size-4" />
                                {label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
