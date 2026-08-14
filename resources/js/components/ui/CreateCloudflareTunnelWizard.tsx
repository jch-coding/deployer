import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { csrfHeaders } from '@/lib/csrf';
import {
    create as createTunnel,
    dns as routeDns,
    login as startLogin,
    login_status as loginStatus,
    run as runTunnel,
} from '@/routes/webhooks/cloudflare_tunnel';

type WizardStep = 'login' | 'name' | 'dns' | 'run';

type JsonResult = {
    ok?: boolean;
    message?: string;
    login_url?: string | null;
    logged_in?: boolean;
    manual?: boolean;
    expected_cname?: string;
    record_name?: string;
    tunnel_id?: string | null;
    status?: Record<string, unknown>;
    errors?: Record<string, string[]>;
};

async function postJson(url: string, body?: Record<string, unknown>): Promise<{ res: Response; data: JsonResult }> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = (await res.json().catch(() => ({}))) as JsonResult;

    return { res, data };
}

async function getJson(url: string): Promise<{ res: Response; data: JsonResult }> {
    const res = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        credentials: 'same-origin',
    });
    const data = (await res.json().catch(() => ({}))) as JsonResult;

    return { res, data };
}

function errorMessage(data: JsonResult, fallback: string): string {
    if (data.message) {
        return data.message;
    }
    if (data.errors) {
        return Object.values(data.errors).flat().join(' ');
    }

    return fallback;
}

type Props = {
    initiallyLoggedIn: boolean;
    defaultName?: string | null;
    defaultHostname?: string | null;
};

export default function CreateCloudflareTunnelWizard({
    initiallyLoggedIn,
    defaultName,
    defaultHostname,
}: Props) {
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<WizardStep>(initiallyLoggedIn ? 'name' : 'login');
    const [busy, setBusy] = useState(false);
    const [name, setName] = useState(defaultName ?? 'deployer');
    const [hostname, setHostname] = useState(defaultHostname ?? '');
    const [updateAppUrl, setUpdateAppUrl] = useState(true);
    const [loginUrl, setLoginUrl] = useState<string | null>(null);
    const [loggedIn, setLoggedIn] = useState(initiallyLoggedIn);
    const [dnsManualHint, setDnsManualHint] = useState<string | null>(null);
    const [statusNote, setStatusNote] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        setStep(initiallyLoggedIn ? 'name' : 'login');
        setLoggedIn(initiallyLoggedIn);
        setName(defaultName ?? 'deployer');
        setHostname(defaultHostname ?? '');
        setUpdateAppUrl(true);
        setLoginUrl(null);
        setDnsManualHint(null);
        setStatusNote(null);
    }, [open, initiallyLoggedIn, defaultName, defaultHostname]);

    useEffect(() => {
        if (!open || step !== 'login' || loggedIn) {
            return;
        }

        const id = window.setInterval(async () => {
            const { data } = await getJson(loginStatus.url());
            if (data.login_url) {
                setLoginUrl(data.login_url);
            }
            if (data.logged_in) {
                setLoggedIn(true);
                setStatusNote(data.message ?? 'Logged in.');
                toast.success(data.message ?? 'Logged in with Cloudflare.');
            }
        }, 2000);

        return () => window.clearInterval(id);
    }, [open, step, loggedIn]);

    const beginLogin = useCallback(async () => {
        setBusy(true);
        setStatusNote(null);
        try {
            const { res, data } = await postJson(startLogin.url());
            if (!res.ok || data.ok === false) {
                toast.error(errorMessage(data, 'Failed to start Cloudflare login.'));

                return;
            }
            if (data.login_url) {
                setLoginUrl(data.login_url);
            }
            if (data.logged_in || (data.message ?? '').toLowerCase().includes('already logged')) {
                setLoggedIn(true);
            }
            setStatusNote(data.message ?? null);
            toast.success(data.message ?? 'Login started.');
        } finally {
            setBusy(false);
        }
    }, []);

    const createNamedTunnel = useCallback(async () => {
        const trimmed = name.trim();
        if (!trimmed) {
            toast.error('Enter a tunnel name.');

            return;
        }
        setBusy(true);
        setStatusNote(null);
        try {
            const { res, data } = await postJson(createTunnel.url(), { name: trimmed });
            if (!res.ok || data.ok === false) {
                toast.error(errorMessage(data, 'Failed to create tunnel.'));

                return;
            }
            setStatusNote(data.message ?? null);
            toast.success(data.message ?? 'Tunnel ready.');
            setStep('dns');
        } finally {
            setBusy(false);
        }
    }, [name]);

    const createDns = useCallback(async () => {
        const trimmedHost = hostname.trim().toLowerCase();
        if (!trimmedHost) {
            toast.error('Enter a public hostname.');

            return;
        }
        setBusy(true);
        setDnsManualHint(null);
        setStatusNote(null);
        try {
            const { res, data } = await postJson(routeDns.url(), {
                name: name.trim(),
                hostname: trimmedHost,
            });
            if (!res.ok || data.ok === false) {
                const msg = errorMessage(data, 'Failed to create DNS route.');
                setDnsManualHint(
                    data.expected_cname
                        ? `${msg} Target should be ${data.expected_cname}.`
                        : msg,
                );
                toast.error(msg);

                return;
            }
            setStatusNote(data.message ?? null);
            toast.success(data.message ?? 'DNS route created.');
            setStep('run');
        } finally {
            setBusy(false);
        }
    }, [hostname, name]);

    const finishRun = useCallback(async () => {
        const trimmedHost = hostname.trim().toLowerCase();
        setBusy(true);
        setStatusNote(null);
        try {
            const { res, data } = await postJson(runTunnel.url(), {
                name: name.trim(),
                hostname: trimmedHost,
                update_app_url: updateAppUrl,
            });
            if (!res.ok || data.ok === false) {
                toast.error(errorMessage(data, 'Failed to start tunnel.'));

                return;
            }
            toast.success(data.message ?? 'Tunnel running.');
            setOpen(false);
            router.reload({ only: ['cloudflare_tunnel', 'events'], preserveScroll: true });
        } finally {
            setBusy(false);
        }
    }, [hostname, name, updateAppUrl]);

    const stepLabel = {
        login: '1. Login',
        name: '2. Name',
        dns: '3. DNS',
        run: '4. Run',
    }[step];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    Create tunnel
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Create Cloudflare tunnel</DialogTitle>
                    <DialogDescription>
                        Wizard: login → create named tunnel → DNS CNAME → run. Step: {stepLabel}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex gap-2 text-xs text-muted-foreground">
                    {(['login', 'name', 'dns', 'run'] as WizardStep[]).map((s) => (
                        <span
                            key={s}
                            className={
                                s === step
                                    ? 'font-semibold text-foreground'
                                    : undefined
                            }
                        >
                            {s}
                        </span>
                    ))}
                </div>

                {statusNote && (
                    <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm">{statusNote}</p>
                )}

                {step === 'login' && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Authenticate once with Cloudflare. Pick the zone that owns the hostname
                            you will use (or set CLOUDFLARE_API_TOKEN for DNS).
                        </p>
                        {loginUrl && (
                            <p className="break-all text-sm">
                                <a
                                    href={loginUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-primary underline"
                                >
                                    {loginUrl}
                                </a>
                            </p>
                        )}
                        <DialogFooter className="gap-2 sm:justify-between">
                            <Button type="button" variant="outline" disabled={busy} onClick={beginLogin}>
                                {loginUrl ? 'Restart login' : 'Start login'}
                            </Button>
                            <Button
                                type="button"
                                disabled={busy || !loggedIn}
                                onClick={() => setStep('name')}
                            >
                                Continue
                            </Button>
                        </DialogFooter>
                    </div>
                )}

                {step === 'name' && (
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="tunnel-wizard-name">Tunnel name</Label>
                            <Input
                                id="tunnel-wizard-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="deployer"
                                autoComplete="off"
                            />
                        </div>
                        <DialogFooter className="gap-2">
                            {!initiallyLoggedIn && (
                                <Button type="button" variant="outline" disabled={busy} onClick={() => setStep('login')}>
                                    Back
                                </Button>
                            )}
                            <Button type="button" disabled={busy} onClick={createNamedTunnel}>
                                Create / use tunnel
                            </Button>
                        </DialogFooter>
                    </div>
                )}

                {step === 'dns' && (
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="tunnel-wizard-hostname">Public hostname</Label>
                            <Input
                                id="tunnel-wizard-hostname"
                                value={hostname}
                                onChange={(e) => setHostname(e.target.value)}
                                placeholder="deployment-cnx.example.com"
                                autoComplete="off"
                            />
                        </div>
                        {dnsManualHint && (
                            <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                                {dnsManualHint}
                            </p>
                        )}
                        <DialogFooter className="gap-2 sm:justify-between">
                            <Button type="button" variant="outline" disabled={busy} onClick={() => setStep('name')}>
                                Back
                            </Button>
                            <div className="flex gap-2">
                                {dnsManualHint && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={busy}
                                        onClick={() => setStep('run')}
                                    >
                                        I added DNS manually
                                    </Button>
                                )}
                                <Button type="button" disabled={busy} onClick={createDns}>
                                    Create DNS
                                </Button>
                            </div>
                        </DialogFooter>
                    </div>
                )}

                {step === 'run' && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Writes <code>~/.cloudflared/config.yml</code>, starts{' '}
                            <code>cloudflared tunnel run</code> inside Sail, and optionally updates{' '}
                            <code>APP_URL</code>.
                        </p>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={updateAppUrl}
                                onChange={(e) => setUpdateAppUrl(e.target.checked)}
                            />
                            Set APP_URL to https://{hostname.trim() || 'hostname'}
                        </label>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" disabled={busy} onClick={() => setStep('dns')}>
                                Back
                            </Button>
                            <Button type="button" disabled={busy} onClick={finishRun}>
                                Start tunnel
                            </Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
