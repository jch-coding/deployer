import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import AppendStepsDialog, {
    type AppendableStep,
} from '@/components/Deployment/AppendStepsDialog';
import TaskDeadlineBar from '@/components/Task/TaskDeadlineBar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show as showDeployment } from '@/routes/deployments';
import { show as showTask } from '@/routes/tasks';
import { cn } from '@/lib/utils';

export type InProgressTaskCard = {
    id: number;
    task_name: string;
    task_type: string;
    deployment_id: number;
    deployment_name: string | null;
    status: string;
    expires_at: string | null;
    can_extend: boolean;
    scheduled_at?: string | null;
    progress: {
        completed: number;
        failed: number;
        in_progress: number;
        total: number;
    };
    workflow: {
        id: number;
        name: string | null;
        status: string;
        steps: string[];
        can_append_steps: boolean;
        appendable_steps: AppendableStep[];
        needs_licensing_for_append: boolean;
    } | null;
};

function progressPercent(progress: InProgressTaskCard['progress']): number {
    if (progress.total <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((progress.completed / progress.total) * 100),
    );
}

function InProgressTaskCardView({ task }: { task: InProgressTaskCard }) {
    const [appendOpen, setAppendOpen] = useState(false);
    const percent = progressPercent(task.progress);
    const canAppend = Boolean(task.workflow?.can_append_steps);

    return (
        <Card data-test={`in-progress-task-card-${task.id}`}>
            <CardHeader className="pb-3">
                <CardTitle className="text-base leading-snug">
                    <Link
                        href={showTask(task.id).url}
                        className="hover:underline"
                        data-test={`in-progress-task-link-${task.id}`}
                    >
                        {task.task_name}
                    </Link>
                </CardTitle>
                {task.deployment_name ? (
                    <p className="text-sm text-muted-foreground">
                        <Link
                            href={showDeployment(task.deployment_id).url}
                            className="hover:underline"
                            data-test={`in-progress-task-deployment-${task.id}`}
                        >
                            {task.deployment_name}
                        </Link>
                    </p>
                ) : null}
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex flex-wrap gap-3 text-sm">
                    <span data-test={`in-progress-task-completed-${task.id}`}>
                        <span className="text-muted-foreground">Completed </span>
                        <span className="font-medium">{task.progress.completed}</span>
                    </span>
                    <span data-test={`in-progress-task-failed-${task.id}`}>
                        <span className="text-muted-foreground">Failed </span>
                        <span className="font-medium">{task.progress.failed}</span>
                    </span>
                    <span data-test={`in-progress-task-running-${task.id}`}>
                        <span className="text-muted-foreground">In progress </span>
                        <span className="font-medium">
                            {task.progress.in_progress}
                        </span>
                    </span>
                </div>

                <div className="space-y-1">
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                        aria-label={`${task.task_name} progress`}
                        data-test={`in-progress-task-progress-${task.id}`}
                    >
                        <div
                            className={cn(
                                'h-full rounded-full transition-[width]',
                                task.progress.failed > 0
                                    ? 'bg-amber-500'
                                    : 'bg-emerald-500',
                            )}
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {task.progress.completed} of {task.progress.total} complete
                        ({percent}%)
                    </p>
                </div>

                <TaskDeadlineBar
                    taskId={task.id}
                    expiresAt={task.expires_at}
                    canExtend={task.can_extend}
                    status={task.status}
                    scheduledAt={task.scheduled_at ?? null}
                    reloadOnly={['in_progress_tasks']}
                />

                {canAppend && task.workflow ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            data-test={`in-progress-task-add-steps-${task.id}`}
                            onClick={() => setAppendOpen(true)}
                        >
                            <Plus className="size-4" aria-hidden />
                            Add steps
                        </Button>
                        <AppendStepsDialog
                            open={appendOpen}
                            onOpenChange={setAppendOpen}
                            workflow={task.workflow}
                            reloadOnly={['in_progress_tasks']}
                        />
                    </>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default function InProgressTaskCards({
    tasks,
}: {
    tasks: InProgressTaskCard[];
}) {
    if (tasks.length === 0) {
        return null;
    }

    return (
        <section className="mt-6 w-full" data-test="in-progress-tasks">
            <h2 className="mb-3 text-center text-lg font-semibold">
                In progress tasks
            </h2>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                {tasks.map((task) => (
                    <InProgressTaskCardView key={task.id} task={task} />
                ))}
            </div>
        </section>
    );
}
