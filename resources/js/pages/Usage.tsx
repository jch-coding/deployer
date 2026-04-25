import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, documentation, usage } from '@/routes';
import { index as clientsIndex } from '@/routes/clients';
import { index as deploymentsIndex } from '@/routes/deployments';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'Usage',
        href: usage().url,
    },
];

const deploymentTasks = [
    {
        title: 'Name Devices',
        description: 'Name or rename devices in Aruba Central according to the names in your device CSV.',
    },
    {
        title: 'Configure LAG, Ethernet and VLAN Interfaces',
        description:
            'Runs LAG, physical Ethernet, and SVI configuration in sequence for the selected devices.',
    },
    {
        title: 'Configure Ethernet Interfaces',
        description: 'Configure physical switch interfaces (mode, VLANs, and related port settings).',
    },
    {
        title: 'Configure Portchannel/LAG interface',
        description: 'Configure aggregate interfaces and member ports from your CSV.',
    },
    {
        title: 'Configure SVI',
        description: 'Configure Layer 3 VLAN interfaces (SVIs) with IP addressing.',
    },
    {
        title: 'Create VSF Profile',
        description: 'Create an auto-stacking VSF profile for stack members, including conductor SKU where required.',
    },
    {
        title: 'Remove VSF profile local overrides',
        description:
            'Clears VLAN, DNS, NTP, and static route local overrides introduced during VSF onboarding.',
    },
    {
        title: 'Associate Devices to Site',
        description: 'Associate devices to a site that already exists in Central.',
    },
    {
        title: 'Associate Devices to Site and Name',
        description: 'Associate devices to a site and set their device names in Central.',
    },
    {
        title: 'Preprovision Devices to Group',
        description: 'Preprovision devices into a Central device group.',
    },
    {
        title: 'Move Devices to Device Group',
        description: 'Move existing devices into a Central device group.',
    },
    {
        title: 'Assign Device Function to Devices',
        description: 'Assign the persona or device function in Central to match your CSV.',
    },
] as const;

export default function Usage() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usage" />
            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Usage</h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        End-to-end flow for connecting Aruba Central, organizing deployments, loading
                        devices, and running automation tasks. CSV column requirements are documented
                        separately.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Add a client</CardTitle>
                        <CardDescription>
                            Clients store Aruba Central API credentials for one customer account.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <ol className="list-inside list-decimal space-y-2">
                            <li>
                                Open{' '}
                                <Link
                                    href={clientsIndex().url}
                                    className="text-primary font-medium underline-offset-4 hover:underline"
                                >
                                    Clients
                                </Link>{' '}
                                from the sidebar.
                            </li>
                            <li>
                                Choose <strong>Add Client</strong> and complete the form: client name,
                                OAuth client ID and secret, customer ID, and Central base URL. Optional
                                Classic API fields are available when your tenant still uses them.
                            </li>
                            <li>
                                Save the client, then set it as the active client from the client card
                                when you are ready to work with its deployments.
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Create a deployment</CardTitle>
                        <CardDescription>
                            Deployments group devices and tasks for one rollout or site build-out.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <ol className="list-inside list-decimal space-y-2">
                            <li>
                                Ensure the correct client is current, then open{' '}
                                <Link
                                    href={deploymentsIndex().url}
                                    className="text-primary font-medium underline-offset-4 hover:underline"
                                >
                                    Deployments
                                </Link>
                                .
                            </li>
                            <li>
                                Use <strong>Add Deployment</strong>, enter a unique name (and optional
                                description), and save. You will return to the deployment list.
                            </li>
                            <li>
                                Open the deployment you created to manage devices and tasks for that
                                rollout.
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Add devices</CardTitle>
                        <CardDescription>
                            Devices are defined with a CSV upload on the deployment page.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <ol className="list-inside list-decimal space-y-2">
                            <li>
                                From the deployment&apos;s page, choose <strong>Add Devices</strong> and
                                select your CSV file.
                            </li>
                            <li>
                                Every row must include <strong>name</strong>, <strong>serial</strong>, and{' '}
                                <strong>device_function</strong> at minimum. Additional columns depend
                                on which tasks you plan to run later.
                            </li>
                            <li>
                                Use <strong>Sample CSV</strong> on the deployment page or see{' '}
                                <Link
                                    href={documentation().url}
                                    className="text-primary font-medium underline-offset-4 hover:underline"
                                >
                                    Documentation
                                </Link>{' '}
                                for required and optional headers per task type.
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Deploy tasks</CardTitle>
                        <CardDescription>
                            Tasks enqueue Central configuration work for selected devices or interfaces.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        <p>
                            On the deployment page, each task appears as a card. Open the card, select
                            the devices (or use filters such as switches or APs where offered), optionally
                            set deployment delay and wait times, then start the task. Progress and status
                            are shown on the task detail view after submission.
                        </p>
                        <p className="font-medium">Tasks you can run in production workflows</p>
                        <ul className="space-y-3">
                            {deploymentTasks.map((task) => (
                                <li key={task.title}>
                                    <span className="font-medium">{task.title}</span>
                                    <span className="text-muted-foreground"> — {task.description}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground border-t pt-4 text-xs">
                            The <strong>Test Task</strong> is omitted here; it is intended for
                            development and queue diagnostics, not for customer deployments.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
