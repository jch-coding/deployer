import { InfoIcon } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export function TaskRequiredColumnsInfo({
    columns,
}: {
    columns: string[];
}) {
    const body =
        columns.length > 0 ? columns.join(', ') : 'No required columns';

    return (
        <div className="absolute top-4 right-4">
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-foreground rounded-md p-1 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        aria-label="Required columns for this task"
                    >
                        <InfoIcon className="size-4" aria-hidden />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="left" className="max-w-xs text-left">
                    <p className="font-semibold">Required columns</p>
                    <p className="text-primary-foreground/90 mt-1 break-words">
                        {body}
                    </p>
                </TooltipContent>
            </Tooltip>
        </div>
    );
}
