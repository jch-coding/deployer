import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, FileText } from 'lucide-react';
import { useState } from 'react';
import CustomWorkflowReportDialog from '@/components/Deployment/CustomWorkflowReportDialog';
import WorkflowDeviceStatusList, {
    type WorkflowDeviceStatusRow,
} from '@/components/Deployment/WorkflowDeviceStatusList';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDeviceLabel } from '@/lib/device-label';
import {
    cancel as cancelWorkflow,
    pause as pauseWorkflow,
    resume as resumeWorkflow,
} from '@/routes/provisioning_workflows';

export type CustomWorkflowRunPayload = {
    id: number;
    name: string | null;
    status: string;
    is_terminal: boolean;
    summary: { in_progress: number; completed: number; failed: number };
    devices: WorkflowDeviceStatusRow[];
    licensing_failures: Array<{
        device_id: number;
        name: string;
        serial: string;
        message: string | null;
    }>;
    can_pause: boolean;
    can_resume: boolean;
};

function SummaryCard({
    title,
    count,
    icon,
}: {
    title: string;
    count: number;
    icon: React.ReactNode;
}) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between pt-6">
                <div>
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="text-3xl font-semibold">{count}</p>
                </div>
                {icon}
            </CardContent>
        </Card>
    );
}

export default function CustomWorkflowRunPanel({
    workflow,
    title,
    deploymentName,
}: {
    workflow: CustomWorkflowRunPayload;
    title?: string;
    deploymentName: string;
}) {
    const [reportOpen, setReportOpen] = useState(false);
    const panelTitle =
        title ??
        (workflow.name ? `Custom run: ${workflow.name}` : 'Custom workflow run');

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">{panelTitle}</h2>
                    <p className="text-sm text-muted-foreground capitalize">
                        Status: {workflow.status.replace('_', ' ')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setReportOpen(true)}
                        data-test="generate-custom-workflow-report"
                    >
                        <FileText className="mr-1 size-4" />
                        Generate report
                    </Button>
                    {workflow.can_pause ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(pauseWorkflow(workflow.id).url)
                            }
                            data-test="pause-custom-workflow"
                        >
                            Pause workflow
                        </Button>
                    ) : null}
                    {workflow.can_pause ? (
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.post(cancelWorkflow(workflow.id).url)
                            }
                            data-test="cancel-custom-workflow"
                        >
                            Cancel workflow
                        </Button>
                    ) : null}
                    {workflow.can_resume ? (
                        <Button
                            onClick={() =>
                                router.post(resumeWorkflow(workflow.id).url)
                            }
                            data-test="resume-custom-workflow"
                        >
                            Resume workflow
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <SummaryCard
                    title="In progress"
                    count={workflow.summary.in_progress}
                    icon={<Clock className="size-5 text-blue-500" />}
                />
                <SummaryCard
                    title="Completed"
                    count={workflow.summary.completed}
                    icon={<CheckCircle2 className="size-5 text-emerald-500" />}
                />
                <SummaryCard
                    title="Failed"
                    count={workflow.summary.failed}
                    icon={<AlertCircle className="size-5 text-red-500" />}
                />
            </div>

            {workflow.licensing_failures.length > 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Licensing failures
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2 text-sm">
                            {workflow.licensing_failures.map((row) => (
                                <li
                                    key={row.device_id}
                                    className="rounded border p-2"
                                >
                                    <span className="font-medium">
                                        {formatDeviceLabel(row.name, row.serial)}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        ({row.serial})
                                    </span>
                                    {row.message ? (
                                        <p className="text-destructive">
                                            {row.message}
                                        </p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Devices</CardTitle>
                </CardHeader>
                <CardContent>
                    <WorkflowDeviceStatusList
                        devices={workflow.devices}
                        showRestartControls
                    />
                </CardContent>
            </Card>
            <CustomWorkflowReportDialog
                open={reportOpen}
                onOpenChange={setReportOpen}
                workflowName={workflow.name}
                deploymentName={deploymentName}
                summary={workflow.summary}
                devices={workflow.devices}
            />
        </div>
    );
}
