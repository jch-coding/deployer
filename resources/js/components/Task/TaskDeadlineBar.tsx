import { router } from '@inertiajs/react';
import { AlarmClock } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { extend as extendTask } from '@/routes/tasks';
import { cn } from '@/lib/utils';

const numberInputClass =
    'w-1/4 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

const DEADLINE_TIMEZONE = 'America/New_York';

function formatDeadline(iso: string): string {
    return new Intl.DateTimeFormat('en-US', {
        timeZone: DEADLINE_TIMEZONE,
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(new Date(iso));
}

function addMinutesToDeadline(iso: string, extraMinutes: number): string {
    const base = new Date(iso);
    const now = new Date();
    const anchor = base.getTime() > now.getTime() ? base : now;

    return new Date(anchor.getTime() + extraMinutes * 60_000).toISOString();
}

export type TaskDeadlineProps = {
    taskId: number;
    expiresAt: string | null;
    canExtend: boolean;
    status?: string;
    className?: string;
};

export default function TaskDeadlineBar({
    taskId,
    expiresAt,
    canExtend,
    status,
    className,
}: TaskDeadlineProps) {
    const [extendHours, setExtendHours] = useState(0);
    const [extendMinutes, setExtendMinutes] = useState(30);
    const [submitting, setSubmitting] = useState(false);
    const [nowMs, setNowMs] = useState(() => Date.now());

    const isOverdue = useMemo(() => {
        if (!expiresAt) {
            return false;
        }

        return new Date(expiresAt).getTime() <= nowMs;
    }, [expiresAt, nowMs]);

    const previewDeadline = useMemo(() => {
        if (!expiresAt) {
            return null;
        }

        const extra = extendHours * 60 + extendMinutes;
        if (extra < 1) {
            return null;
        }

        return formatDeadline(addMinutesToDeadline(expiresAt, extra));
    }, [expiresAt, extendHours, extendMinutes]);

    useEffect(() => {
        if (!expiresAt) {
            return;
        }

        const interval = window.setInterval(() => {
            setNowMs(Date.now());
        }, 30_000);

        return () => window.clearInterval(interval);
    }, [expiresAt]);

    const submitExtend = () => {
        const extra = extendHours * 60 + extendMinutes;
        if (extra < 1 || submitting) {
            return;
        }

        setSubmitting(true);
        router.post(
            extendTask(taskId).url,
            {
                hours: extendHours,
                minutes: extendMinutes,
            },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    if (!expiresAt) {
        return null;
    }

    const runningLike =
        status === 'IN_PROGRESS' ||
        status === 'running' ||
        status === 'paused';

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 text-sm',
                className,
            )}
            data-test="task-deadline-bar"
        >
            <p
                className={cn(
                    'text-muted-foreground',
                    isOverdue && runningLike && 'font-medium text-destructive',
                )}
            >
                <span className="font-medium text-foreground">Ends</span>{' '}
                {formatDeadline(expiresAt)}
                {isOverdue && runningLike ? ' (overdue)' : ''}
            </p>
            {canExtend ? (
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            data-test="extend-task-deadline"
                        >
                            <AlarmClock className="mr-1 size-4" />
                            Extend
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Extend task deadline</DialogTitle>
                        <DialogDescription>
                            Add time to the current end date and time. Work
                            will keep running with the updated window.
                        </DialogDescription>
                        <div className="flex gap-2">
                            <label
                                htmlFor={`extend-hours-${taskId}`}
                                className="self-center"
                            >
                                Hours
                            </label>
                            <input
                                id={`extend-hours-${taskId}`}
                                type="number"
                                min={0}
                                value={extendHours}
                                onChange={(event) =>
                                    setExtendHours(
                                        Number.parseInt(event.target.value, 10) ||
                                            0,
                                    )
                                }
                                className={numberInputClass}
                            />
                            <label
                                htmlFor={`extend-minutes-${taskId}`}
                                className="self-center"
                            >
                                Minutes
                            </label>
                            <input
                                id={`extend-minutes-${taskId}`}
                                type="number"
                                min={0}
                                value={extendMinutes}
                                onChange={(event) =>
                                    setExtendMinutes(
                                        Number.parseInt(event.target.value, 10) ||
                                            0,
                                    )
                                }
                                className={numberInputClass}
                            />
                        </div>
                        {previewDeadline ? (
                            <p className="text-muted-foreground text-sm">
                                New end: {previewDeadline}
                            </p>
                        ) : null}
                        <DialogFooter className="sm:justify-start">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    disabled={
                                        submitting ||
                                        extendHours * 60 + extendMinutes < 1
                                    }
                                    onClick={submitExtend}
                                    data-test="extend-task-deadline-submit"
                                >
                                    {submitting ? 'Extending…' : 'Extend deadline'}
                                </Button>
                            </DialogClose>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </div>
    );
}
