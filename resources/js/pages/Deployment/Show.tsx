import { Form, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { store } from '@/actions/App/Http/Controllers/DeploymentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@headlessui/react';

export default function Show() {
    const deployment = usePage().props.deployment;
    const devices = usePage().props.devices;

    return (
        <div>
            <h1>{deployment.name}</h1>
            {
                deployment.devices.length > 0 ?
                <ul>
                    {devices.map(device => (
                        <li key={device.id}>{device.name}</li>
                    ))}
                </ul>
                    :
                    <p>No devices assigned to this deployment</p>
            }
        </div>
    )
}
