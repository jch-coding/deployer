import { Check, ChevronDown } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

type PassedInterfacesSummaryProps = {
    count: number;
    emptyMessage: string;
    children: ReactNode;
};

export default function PassedInterfacesSummary({
    count,
    emptyMessage,
    children,
}: PassedInterfacesSummaryProps) {
    const [open, setOpen] = useState(false);

    if (count === 0) {
        return (
            <p className="text-muted-foreground text-sm dark:text-white/80">{emptyMessage}</p>
        );
    }

    const summaryLabel = `${count} interface${count === 1 ? '' : 's'} passed verification`;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex items-center gap-3 rounded-md border border-border py-2.5 pl-3 pr-1 dark:border-white/20 dark:text-white">
                <Check
                    className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                    aria-hidden
                />
                <CollapsibleTrigger asChild>
                    <Button
                        variant="ghost"
                        className="min-w-0 flex-1 justify-between px-2 font-normal dark:text-white"
                        aria-expanded={open}
                        aria-label={
                            open
                                ? `Hide ${summaryLabel}`
                                : `Show ${summaryLabel}`
                        }
                    >
                        <span className="text-sm">{summaryLabel}</span>
                        <ChevronDown
                            className={cn(
                                'size-4 shrink-0 transition-transform',
                                open && 'rotate-180',
                            )}
                            aria-hidden
                        />
                    </Button>
                </CollapsibleTrigger>
            </div>
            <CollapsibleContent>
                <div className="divide-y divide-border dark:divide-white/20">
                    {children}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
