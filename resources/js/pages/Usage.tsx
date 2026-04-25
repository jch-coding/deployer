import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/app-layout';
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

const tocSections = [
    { id: 'introduction', label: 'Introduction' },
    { id: 'add-a-client', label: 'Add a client' },
    { id: 'create-a-deployment', label: 'Create a deployment' },
    { id: 'add-devices', label: 'Add devices' },
    { id: 'how-deployment-tasks-work', label: 'How tasks work' },
    { id: 'available-tasks', label: 'Available tasks' },
] as const;

const deploymentTasks = [
    {
        title: 'Name Devices',
        description: 'Name or rename devices in Aruba Central according to the names in your device CSV.',
        requiresClassicCentral: false,
    },
    {
        title: 'Configure LAG, Ethernet and VLAN Interfaces',
        description:
            'Runs LAG, physical Ethernet, and SVI configuration in sequence for the selected devices.',
        requiresClassicCentral: false,
    },
    {
        title: 'Configure Ethernet Interfaces',
        description: 'Configure physical switch interfaces (mode, VLANs, and related port settings).',
        requiresClassicCentral: false,
    },
    {
        title: 'Configure Portchannel/LAG interface',
        description: 'Configure aggregate interfaces and member ports from your CSV.',
        requiresClassicCentral: false,
    },
    {
        title: 'Configure SVI',
        description: 'Configure Layer 3 VLAN interfaces (SVIs) with IP addressing.',
        requiresClassicCentral: false,
    },
    {
        title: 'Create VSF Profile',
        description: 'Create an auto-stacking VSF profile for stack members, including conductor SKU where required.',
        requiresClassicCentral: false,
    },
    {
        title: 'Remove VSF profile local overrides',
        description:
            'Clears VLAN, DNS, NTP, and static route local overrides introduced during VSF onboarding.',
        requiresClassicCentral: false,
    },
    {
        title: 'Associate Devices to Site',
        description: 'Associate devices to a site that already exists in Central.',
        requiresClassicCentral: true,
    },
    {
        title: 'Associate Devices to Site and Name',
        description: 'Associate devices to a site and set their device names in Central.',
        requiresClassicCentral: true,
    },
    {
        title: 'Preprovision Devices to Group',
        description: 'Preprovision devices into a Central device group.',
        requiresClassicCentral: true,
    },
    {
        title: 'Move Devices to Device Group',
        description: 'Move existing devices into a Central device group.',
        requiresClassicCentral: true,
    },
    {
        title: 'Assign Device Function to Devices',
        description: 'Assign the persona or device function in Central to match your CSV.',
        requiresClassicCentral: false,
    },
] as const;

function scrollToSection(id: string) {
    const el = document.getElementById(id);
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function Usage() {
    const [activeId, setActiveId] = useState<string>(tocSections[0].id);
    const scrollSpyRaf = useRef<number | null>(null);

    const updateActiveFromScroll = useCallback(() => {
        const headerOffset = 96;
        const activationY = headerOffset;

        let current = tocSections[0].id;
        for (const { id } of tocSections) {
            const el = document.getElementById(id);
            if (!el) {
                continue;
            }
            const top = el.getBoundingClientRect().top;
            if (top <= activationY + 8) {
                current = id;
            }
        }
        setActiveId(current);
    }, []);

    useEffect(() => {
        const onScroll = () => {
            if (scrollSpyRaf.current !== null) {
                cancelAnimationFrame(scrollSpyRaf.current);
            }
            scrollSpyRaf.current = requestAnimationFrame(() => {
                scrollSpyRaf.current = null;
                updateActiveFromScroll();
            });
        };

        updateActiveFromScroll();

        const inset = document.querySelector<HTMLElement>('[data-slot="sidebar-inset"]');
        window.addEventListener('scroll', onScroll, { passive: true });
        inset?.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            inset?.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            if (scrollSpyRaf.current !== null) {
                cancelAnimationFrame(scrollSpyRaf.current);
            }
        };
    }, [updateActiveFromScroll]);

    const body = 'text-[15px] leading-relaxed text-foreground/90';
    const h2 = 'scroll-mt-24 text-xl font-semibold tracking-tight text-foreground';
    const linkClass =
        'text-primary font-medium underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usage" />
            <div className="mx-auto flex w-full max-w-6xl gap-10 px-4 py-6 lg:px-8">
                <article className="min-w-0 flex-1 max-w-3xl">
                    <header className="border-b border-border pb-8">
                        <h1 className="text-3xl font-semibold tracking-tight">Usage</h1>
                        <p className="text-muted-foreground mt-3 max-w-2xl text-[15px] leading-relaxed">
                            End-to-end flow for connecting Aruba Central, organizing deployments, loading
                            devices, and running automation tasks. CSV column requirements are documented
                            separately.
                        </p>
                    </header>

                    <section id="introduction" className="border-b border-border py-10">
                        <h2 className={h2}>Introduction</h2>
                        <p className={cn(body, 'mt-4')}>
                            Deployer ties together <strong>clients</strong> (Central credentials),{' '}
                            <strong>deployments</strong> (rollouts or sites), <strong>devices</strong> (from
                            CSV), and <strong>tasks</strong> (Central automation). Work generally moves in
                            that order: add a client, pick it as current, create a deployment, upload
                            devices, then run tasks from the deployment page.
                        </p>
                    </section>

                    <section id="add-a-client" className="border-b border-border py-10">
                        <h2 className={h2}>Add a client</h2>
                        <p className={cn(body, 'mt-4')}>
                            Clients store Aruba Central API credentials for one customer account. You need at
                            least one client before deployments and devices are meaningful.
                        </p>
                        <ol className={cn(body, 'mt-4 list-decimal space-y-2 pl-5')}>
                            <li>
                                Open{' '}
                                <Link href={clientsIndex().url} prefetch className={linkClass}>
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
                                Save the client, then set it as the active client from the client card when
                                you are ready to work with its deployments.
                            </li>
                        </ol>
                    </section>

                    <section id="create-a-deployment" className="border-b border-border py-10">
                        <h2 className={h2}>Create a deployment</h2>
                        <p className={cn(body, 'mt-4')}>
                            Deployments group devices and tasks for one rollout or site build-out. Each
                            deployment belongs to the current client.
                        </p>
                        <ol className={cn(body, 'mt-4 list-decimal space-y-2 pl-5')}>
                            <li>
                                Ensure the correct client is current, then open{' '}
                                <Link href={deploymentsIndex().url} prefetch className={linkClass}>
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
                    </section>

                    <section id="add-devices" className="border-b border-border py-10">
                        <h2 className={h2}>Add devices</h2>
                        <p className={cn(body, 'mt-4')}>
                            Devices are defined with a CSV upload on the deployment page. Rows are merged by
                            serial so you can refine the same hardware over time.
                        </p>
                        <ol className={cn(body, 'mt-4 list-decimal space-y-2 pl-5')}>
                            <li>
                                From the deployment&apos;s page, choose <strong>Add Devices</strong> and
                                select your CSV file.
                            </li>
                            <li>
                                Every row must include <strong>name</strong>, <strong>serial</strong>, and{' '}
                                <strong>device_function</strong> at minimum. Additional columns depend on
                                which tasks you plan to run later.
                            </li>
                            <li>
                                Use <strong>Sample CSV</strong> on the deployment page or see{' '}
                                <Link href={documentation().url} prefetch className={linkClass}>
                                    Documentation
                                </Link>{' '}
                                for required and optional headers per task type.
                            </li>
                        </ol>
                    </section>

                    <section id="how-deployment-tasks-work" className="border-b border-border py-10">
                        <h2 className={h2}>How deployment tasks work</h2>
                        <p className={cn(body, 'mt-4')}>
                            On the deployment page, each task appears as a card. Open the card, select the
                            devices (or use filters such as switches or APs where offered), optionally set
                            deployment delay and wait times, then start the task. Progress and status are
                            shown on the task detail view after submission.
                        </p>
                        <p className={cn(body, 'mt-4')}>
                            Interface-oriented tasks attach to matching interface rows from your CSV;
                            device-oriented tasks run against the devices you select in the card.
                        </p>
                    </section>

                    <section id="available-tasks" className="py-10">
                        <h2 className={h2}>Available tasks</h2>
                        <p className={cn(body, 'mt-4')}>
                            The following tasks are the ones you would use in a production workflow. Each
                            expects your CSV to include the columns described in Documentation for that
                            task.
                        </p>
                        <div
                            className="mt-6 rounded-lg border border-border bg-muted/40 px-4 py-3 text-[15px] leading-relaxed text-foreground/90"
                            role="note"
                        >
                            <p className="font-medium text-foreground">Classic Central credentials</p>
                            <p className="mt-2">
                                These tasks call Aruba Central&apos;s <strong>classic</strong> REST APIs and
                                will not succeed unless the current client has{' '}
                                <strong>Classic API</strong> credentials saved (classic client ID and secret,
                                username and password, and the classic base URL derived from your Central
                                region). That applies to: <strong>Associate Devices to Site</strong>,{' '}
                                <strong>Associate Devices to Site and Name</strong>,{' '}
                                <strong>Preprovision Devices to Group</strong>, and{' '}
                                <strong>Move Devices to Device Group</strong>. Enter those fields when you add
                                or edit a client, under the optional Classic API section.
                            </p>
                        </div>
                        <ul className={cn(body, 'mt-6 list-disc space-y-3 pl-5')}>
                            {deploymentTasks.map((task) => (
                                <li key={task.title}>
                                    <strong>{task.title}</strong>
                                    {task.requiresClassicCentral ? (
                                        <>
                                            {' '}
                                            <Badge variant="outline" className="ml-0.5 align-middle text-xs font-normal">
                                                Classic Central API
                                            </Badge>
                                        </>
                                    ) : null}
                                    {' — '}
                                    {task.description}
                                </li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground mt-8 border-t border-border pt-6 text-sm leading-relaxed">
                            The <strong>Test Task</strong> is omitted here; it is intended for development
                            and queue diagnostics, not for customer deployments.
                        </p>
                    </section>
                </article>

                <aside
                    className="hidden w-52 shrink-0 self-start lg:sticky lg:top-24 lg:block"
                    aria-label="On this page"
                >
                    <p className="text-muted-foreground mb-3 text-xs font-medium uppercase tracking-wide">
                        On this page
                    </p>
                    <nav>
                        <ul className="space-y-1 text-sm">
                            {tocSections.map(({ id, label }) => (
                                <li key={id}>
                                    <button
                                        type="button"
                                        onClick={() => scrollToSection(id)}
                                        className={cn(
                                            'w-full rounded-md px-2 py-1.5 text-left transition-colors',
                                            activeId === id
                                                ? 'bg-muted text-foreground font-medium'
                                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                        )}
                                    >
                                        {label}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </aside>
            </div>
        </AppLayout>
    );
}
