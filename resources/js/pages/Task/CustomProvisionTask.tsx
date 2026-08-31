import { Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import CustomWorkflowRunPanel, {
    type CustomWorkflowRunPayload,
} from '@/components/Deployment/CustomWorkflowRunPanel';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { index as taskIndex, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';

type CustomProvisionTaskPageProps = SharedData & {
    task: {
        id: number;
        status: string;
        deployment_time: number;
        wait_time: number;
    };
    deployment: {
        id: number;
        name: string;
    };
    workflow: CustomWorkflowRunPayload;
};

function taskPageTitle(workflow: CustomWorkflowRunPayload): string {
    const base = 'Custom Task.';
    if (workflow.name?.trim()) {
        return `${base} — ${workflow.name.trim()}`;
    }

    return base;
}

export default function CustomProvisionTask() {
    const { current_client, deployment, task, workflow, flash } =
        usePage<CustomProvisionTaskPageProps>().props;

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    const shouldPoll = workflow.status === 'running';
    useEffect(() => {
        if (!shouldPoll) {
            return;
        }

        const { stop } = router.poll(2000);

        return () => stop();
    }, [shouldPoll]);

    const pageTitle = taskPageTitle(workflow);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: current_client?.name ?? 'Clients', href: clientIndex().url },
        { title: deployment.name, href: showDeployment(deployment.id).url },
        { title: 'Tasks', href: taskIndex().url },
        { title: pageTitle, href: showTask(task.id).url },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{pageTitle}</h1>
                        <p className="text-sm text-muted-foreground">
                            Custom provisioning workflow for {deployment.name}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={showDeployment(deployment.id).url}>
                            Back to deployment
                        </Link>
                    </Button>
                </div>

                <CustomWorkflowRunPanel workflow={workflow} title={pageTitle} />
            </div>
        </AppLayout>
    );
}
