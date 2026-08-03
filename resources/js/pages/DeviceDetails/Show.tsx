import { Link, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useMemo } from 'react';
import AccessPointDetailsPanel, {
    type AccessPointDetailsPayload,
} from '@/components/device-details/AccessPointDetailsPanel';
import SwitchInterfacesPanel, {
    type SwitchDetailsPayload,
} from '@/components/device-details/SwitchInterfacesPanel';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { downloadAllSwitchInterfacesCsv } from '@/lib/switch-interfaces-csv';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex } from '@/routes/device-details';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeviceDetailsPayload = SwitchDetailsPayload & {
    device_type: string;
    device_function: string;
};

type DeviceDetailsShowProps = {
    devices: DeviceDetailsPayload[];
} & SharedData;

function deviceDisplayName(device: DeviceDetailsPayload): string {
    return device.device_name !== '' ? device.device_name : device.serial;
}

function isAccessPoint(device: DeviceDetailsPayload): boolean {
    return device.device_type === 'ACCESS_POINT';
}

export default function Show() {
    const { current_client, devices } = usePage<DeviceDetailsShowProps>().props;

    const accessPoints = useMemo(() => devices.filter(isAccessPoint), [devices]);
    const switches = useMemo(() => devices.filter((device) => !isAccessPoint(device)), [devices]);

    const pageTitle = useMemo(() => {
        if (devices.length === 0) {
            return 'Device Details';
        }
        if (devices.length === 1) {
            return deviceDisplayName(devices[0]);
        }

        if (accessPoints.length === devices.length) {
            return `${devices.length} access points`;
        }

        if (switches.length === devices.length) {
            return `${devices.length} switches`;
        }

        return `${devices.length} devices`;
    }, [accessPoints.length, devices, switches.length]);

    const subtitle = useMemo(() => {
        if (devices.length <= 1) {
            return null;
        }

        if (accessPoints.length === devices.length) {
            return `Viewing details for ${devices.length} selected access points.`;
        }

        if (switches.length === devices.length) {
            return `Viewing interfaces for ${devices.length} selected switches.`;
        }

        return `Viewing details for ${switches.length} switch${switches.length === 1 ? '' : 'es'} and ${accessPoints.length} access point${accessPoints.length === 1 ? '' : 's'}.`;
    }, [accessPoints.length, devices.length, switches.length]);

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
                        {subtitle ? (
                            <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={deviceDetailsIndex().url} data-test="device-details-back-link">
                                Back to search
                            </Link>
                        </Button>
                        {switches.length > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="gap-2"
                                disabled={totalInterfaces === 0}
                                onClick={() =>
                                    downloadAllSwitchInterfacesCsv(
                                        switches.map((item) => ({
                                            switchName: deviceDisplayName(item),
                                            interfaces: item.interfaces,
                                        })),
                                    )
                                }
                                data-test="device-details-export-all-csv"
                            >
                                <Download className="size-4" aria-hidden />
                                Export All CSV
                            </Button>
                        ) : null}
                    </div>
                </div>

                {devices.map((device) =>
                    isAccessPoint(device) ? (
                        <AccessPointDetailsPanel
                            key={device.serial}
                            accessPoint={device as AccessPointDetailsPayload}
                        />
                    ) : (
                        <SwitchInterfacesPanel key={device.serial} switchDetails={device} />
                    ),
                )}
            </div>
        </AppLayout>
    );
}
