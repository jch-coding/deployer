import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

export type DiffEntry = {
    path: string;
    expected: unknown;
    actual: unknown;
};

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function valuesMatch(expected: unknown, actual: unknown): boolean {
    return formatValue(expected) === formatValue(actual);
}

type ConfigurationDiffProps = {
    details: DiffEntry[];
    ok?: boolean;
    /** @deprecated Use details instead */
    diff?: DiffEntry[];
    /** Render only the comparison table (parent provides collapsible trigger). */
    contentOnly?: boolean;
};

export function ConfigurationDiffTable({
    rows,
    className,
}: {
    rows: DiffEntry[];
    className?: string;
}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'overflow-x-auto rounded-md border text-xs dark:border-white/20 dark:text-white',
                className,
            )}
        >
            <table className="w-full min-w-[28rem]">
                <thead>
                    <tr className="border-b bg-muted/50 text-left dark:border-white/20 dark:bg-white/10 dark:text-white">
                        <th className="px-3 py-2 font-medium">Field</th>
                        <th className="px-3 py-2 font-medium">Expected</th>
                        <th className="px-3 py-2 font-medium">Central</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((entry) => {
                        const matches = valuesMatch(entry.expected, entry.actual);

                        return (
                            <tr
                                key={entry.path}
                                className="border-b last:border-0 dark:border-white/20"
                            >
                                <td className="px-3 py-2 font-mono dark:text-white">
                                    {entry.path}
                                </td>
                                <td
                                    className={cn(
                                        'px-3 py-2',
                                        matches
                                            ? 'text-emerald-700 dark:text-white'
                                            : 'text-emerald-700 dark:text-white',
                                    )}
                                >
                                    {formatValue(entry.expected)}
                                </td>
                                <td
                                    className={cn(
                                        'px-3 py-2',
                                        matches
                                            ? 'text-emerald-700 dark:text-white'
                                            : 'text-red-700 dark:text-white',
                                    )}
                                >
                                    {formatValue(entry.actual)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

export default function ConfigurationDiff({
    details,
    ok = false,
    diff = [],
    contentOnly = false,
}: ConfigurationDiffProps) {
    const [open, setOpen] = useState(false);
    const rows = details.length > 0 ? details : diff;
    const mismatchCount = rows.filter(
        (entry) => !valuesMatch(entry.expected, entry.actual),
    ).length;

    if (rows.length === 0) {
        return null;
    }

    if (contentOnly) {
        return <ConfigurationDiffTable rows={rows} className="mt-2" />;
    }

    const triggerLabel = ok
        ? `View configuration details (${rows.length} field${rows.length === 1 ? '' : 's'})`
        : mismatchCount > 0
          ? `View ${mismatchCount} difference${mismatchCount === 1 ? '' : 's'} (${rows.length} fields)`
          : `View configuration details (${rows.length} field${rows.length === 1 ? '' : 's'})`;

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-2">
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 gap-1 px-2 text-xs dark:text-white"
                >
                    <ChevronDown
                        className={cn(
                            'size-4 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                    {triggerLabel}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ConfigurationDiffTable rows={rows} className="mt-2" />
            </CollapsibleContent>
        </Collapsible>
    );
}
