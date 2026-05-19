import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const numberInputClass =
    'w-1/4 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

type TaskDurationDialogProps = {
    trigger: ReactNode;
    deploymentTimeHours: number;
    deploymentTimeMinutes: number;
    waitTimeMinutes: number;
    onDeploymentTimeHoursChange: (value: number) => void;
    onDeploymentTimeMinutesChange: (value: number) => void;
    onWaitTimeMinutesChange: (value: number) => void;
    footer: ReactNode;
    title?: string;
    description?: string;
    tooltipLabel?: string;
    hoursInputId?: string;
    minutesInputId?: string;
    waitTimeInputId?: string;
};

export default function TaskDurationDialog({
    trigger,
    deploymentTimeHours,
    deploymentTimeMinutes,
    waitTimeMinutes,
    onDeploymentTimeHoursChange,
    onDeploymentTimeMinutesChange,
    onWaitTimeMinutesChange,
    footer,
    title = 'Set Task Duration',
    description = 'Set the duration of the task',
    tooltipLabel = 'Set duration',
    hoursInputId = 'deployment-time-hours',
    minutesInputId = 'deployment-time-minutes',
    waitTimeInputId = 'wait-time-minutes',
}: TaskDurationDialogProps) {
    const parseNumber = (value: string) => {
        const parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    };

    return (
        <Dialog>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DialogTrigger asChild>{trigger}</DialogTrigger>
                </TooltipTrigger>
                <TooltipContent side="top">
                    <p>{tooltipLabel}</p>
                </TooltipContent>
            </Tooltip>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <div className="flex gap-2">
                    <label htmlFor={hoursInputId} className="self-center">
                        Hours
                    </label>
                    <input
                        id={hoursInputId}
                        type="number"
                        value={deploymentTimeHours}
                        onChange={(e) => onDeploymentTimeHoursChange(parseNumber(e.target.value))}
                        className={numberInputClass}
                    />
                    <label htmlFor={minutesInputId} className="self-center">
                        Minutes
                    </label>
                    <input
                        id={minutesInputId}
                        type="number"
                        value={deploymentTimeMinutes}
                        onChange={(e) => onDeploymentTimeMinutesChange(parseNumber(e.target.value))}
                        className={numberInputClass}
                    />
                </div>
                <div>
                    <label htmlFor={waitTimeInputId} className="self-center pr-2">
                        Retry Interval
                    </label>
                    <input
                        id={waitTimeInputId}
                        type="number"
                        value={waitTimeMinutes}
                        onChange={(e) => onWaitTimeMinutesChange(parseNumber(e.target.value))}
                        className={numberInputClass}
                    />
                    <i className="pl-2 text-slate-400">in minutes</i>
                </div>
                <DialogFooter className="sm:justify-start">{footer}</DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
