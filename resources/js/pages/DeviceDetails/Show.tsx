import { Link, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useMemo } from 'react';
import SwitchInterfacesPanel, {
    type SwitchDetailsPayload,
} from '@/components/device-details/SwitchInterfacesPanel';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { downloadAllSwitchInterfacesCsv } from '@/lib/switch-interfaces-csv';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex } from '@/routes/device-details';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeviceDetailsShowProps = {
    switches: SwitchDetailsPayload[];
} & SharedData;

function switchDisplayName(switchDetails: SwitchDetailsPayload): string {
    return switchDetails.device_name !== '' ? switchDetails.device_name : switchDetails.serial;
}

export default function Show() {
    const { current_client, switches } = usePage<DeviceDetailsShowProps>().props;

    const pageTitle = useMemo(() => {
        if (switches.length === 0) {
            return 'Device Details';
        }
        if (switches.length === 1) {
            return switchDisplayName(switches[0]);
        }

        return `${switches.length} switches`;
    }, [switches]);

    const totalInterfaces = useMemo(
        () => switches.reduce((sum, item) => sum + item.interfaces.length, 0),
        [switches],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Device Details',
            href: deviceDetailsIndex().url,
        },
        {
            title: pageTitle,
            href: '#',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-3xl font-semibold" data-test="device-details-show-title">
                            {pageTitle}
                        </h1>
                        {switches.length > 1 ? (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Viewing interfaces for {switches.length} selected switches.
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={deviceDetailsIndex().url} data-test="device-details-back-link">
                                Back to search
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="gap-2"
                            disabled={totalInterfaces === 0}
                            onClick={() =>
                                downloadAllSwitchInterfacesCsv(
                                    switches.map((item) => ({
                                        switchName: switchDisplayName(item),
                                        interfaces: item.interfaces,
                                    })),
                                )
                            }
                            data-test="device-details-export-all-csv"
                        >
                            <Download className="size-4" aria-hidden />
                            Export All CSV
                        </Button>
                    </div>
                </div>

                {switches.map((switchDetails) => (
                    <SwitchInterfacesPanel
                        key={switchDetails.serial}
                        switchDetails={switchDetails}
                    />
                ))}
            </div>
        </AppLayout>
    );
}
