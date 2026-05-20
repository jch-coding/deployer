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

export default function ConfigurationDiff({ diff }: { diff: DiffEntry[] }) {
    const [open, setOpen] = useState(false);

    if (diff.length === 0) {
        return null;
    }

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-2">
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 gap-1 px-2 text-xs"
                >
                    <ChevronDown
                        className={cn(
                            'size-4 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                    View {diff.length} difference{diff.length === 1 ? '' : 's'}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mt-2 overflow-x-auto rounded-md border text-xs">
                    <table className="w-full min-w-[28rem]">
                        <thead>
                            <tr className="border-b bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Field</th>
                                <th className="px-3 py-2 font-medium">Expected</th>
                                <th className="px-3 py-2 font-medium">Central</th>
                            </tr>
                        </thead>
                        <tbody>
                            {diff.map((entry) => (
                                <tr key={entry.path} className="border-b last:border-0">
                                    <td className="px-3 py-2 font-mono">{entry.path}</td>
                                    <td className="px-3 py-2 text-emerald-700">
                                        {formatValue(entry.expected)}
                                    </td>
                                    <td className="px-3 py-2 text-red-700">
                                        {formatValue(entry.actual)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
