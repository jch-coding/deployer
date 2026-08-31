import type { ReactNode } from 'react';
import { Loader2, RotateCcw } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { csrfHeaders } from '@/lib/csrf';
import { reboot as rebootRoute } from '@/routes/device-details';

type RebootWhen = 'now' | 'in_5_minutes' | 'in_10_minutes' | 'at';

type RebootResult = {
    serial: string;
    ok: boolean;
    error: string | null;
    status: number | null;
};

type RebootResponse = {
    scheduled: boolean;
    scheduled_at: string | null;
    serials: string[];
    results: RebootResult[];
    error: string | null;
    message?: string;
    errors?: Record<string, string[]>;
};

type RebootAccessPointsDialogProps = {
    serials: string[];
    trigger: ReactNode;
};

function formatScheduledAt(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleString();
}

function defaultCustomDateTime(): string {
    const date = new Date();
    date.setMinutes(date.getMinutes() + 15);
    date.setSeconds(0, 0);

    const offset = date.getTimezoneOffset();
    const local = new Date(date.getTime() - offset * 60_000);

    return local.toISOString().slice(0, 16);
}

function minCustomDateTime(): string {
    const date = new Date();
    date.setMinutes(date.getMinutes() + 1);
    date.setSeconds(0, 0);

    const offset = date.getTimezoneOffset();
    const local = new Date(date.getTime() - offset * 60_000);

    return local.toISOString().slice(0, 16);
}

export default function RebootAccessPointsDialog({
    serials,
    trigger,
}: RebootAccessPointsDialogProps) {
    const [open, setOpen] = useState(false);
    const [when, setWhen] = useState<RebootWhen>('now');
    const [rebootAt, setRebootAt] = useState(defaultCustomDateTime);
    const [loading, setLoading] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [results, setResults] = useState<RebootResult[] | null>(null);

    const uniqueSerials = useMemo(
        () => [...new Set(serials.map((serial) => serial.trim()).filter((serial) => serial !== ''))],
        [serials],
    );

    const deviceLabel = useMemo(() => {
        if (uniqueSerials.length === 1) {
            return `access point ${uniqueSerials[0]}`;
        }

        return `${uniqueSerials.length} access points`;
    }, [uniqueSerials]);

    const resetForm = useCallback(() => {
        setWhen('now');
        setRebootAt(defaultCustomDateTime());
        setSubmitError(null);
        setResults(null);
    }, []);

    const handleOpenChange = useCallback(
        (nextOpen: boolean) => {
            setOpen(nextOpen);
            if (!nextOpen) {
                resetForm();
            }
        },
        [resetForm],
    );

    const handleSubmit = useCallback(async () => {
        if (uniqueSerials.length === 0) {
            setSubmitError('At least one serial number is required.');
            return;
        }

        if (when === 'at' && rebootAt === '') {
            setSubmitError('Select a date and time for the reboot.');
            return;
        }

        setLoading(true);
        setSubmitError(null);
        setResults(null);

        try {
            const body: {
                serials: string[];
                when: RebootWhen;
                reboot_at?: string;
            } = {
                serials: uniqueSerials,
                when,
            };

            if (when === 'at') {
                const parsed = new Date(rebootAt);
                if (Number.isNaN(parsed.getTime())) {
                    throw new Error('Select a valid date and time for the reboot.');
                }

                if (parsed.getTime() <= Date.now()) {
                    throw new Error('Reboot time must be in the future.');
                }

                body.reboot_at = parsed.toISOString();
            }

            const response = await fetch(rebootRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const payload = (await response.json().catch(() => null)) as RebootResponse | null;

            if (!response.ok) {
                const validationMessage = payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : null;

                throw new Error(
                    payload?.error ??
                        validationMessage ??
                        payload?.message ??
                        `Failed to schedule reboot (HTTP ${response.status}).`,
                );
            }

            if (payload === null) {
                throw new Error('Failed to schedule reboot: empty response.');
            }

            if (payload.error) {
                throw new Error(payload.error);
            }

            if (payload.scheduled) {
                const scheduledLabel = payload.scheduled_at
                    ? formatScheduledAt(payload.scheduled_at)
                    : 'the selected time';

                toast.success(
                    `Reboot scheduled for ${uniqueSerials.length} access point${uniqueSerials.length === 1 ? '' : 's'} at ${scheduledLabel}.`,
                );
                setOpen(false);
                resetForm();

                return;
            }

            const rebootResults = payload.results ?? [];
            setResults(rebootResults);

            const successCount = rebootResults.filter((result) => result.ok).length;
            const failureCount = rebootResults.length - successCount;

            if (failureCount === 0) {
                toast.success(
                    `Reboot initiated for ${successCount} access point${successCount === 1 ? '' : 's'}.`,
                );
                setOpen(false);
                resetForm();

                return;
            }

            if (successCount > 0) {
                toast.warning(
                    `Reboot initiated for ${successCount} access point${successCount === 1 ? '' : 's'}, but ${failureCount} failed.`,
                );
            } else {
                toast.error(`Failed to reboot ${failureCount} access point${failureCount === 1 ? '' : 's'}.`);
            }
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Failed to schedule reboot.');
        } finally {
            setLoading(false);
        }
    }, [rebootAt, resetForm, uniqueSerials, when]);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent data-test="device-details-reboot-dialog">
                <DialogTitle>Reboot access point{uniqueSerials.length === 1 ? '' : 's'}</DialogTitle>
                <DialogDescription>
                    This will reboot {deviceLabel}. Connected clients will be disconnected during the
                    reboot.
                </DialogDescription>

                <div className="space-y-3">
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">When should the reboot happen?</legend>
                        <div className="space-y-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="reboot-when"
                                    value="now"
                                    checked={when === 'now'}
                                    onChange={() => setWhen('now')}
                                    data-test="device-details-reboot-when-now"
                                />
                                Now
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="reboot-when"
                                    value="in_5_minutes"
                                    checked={when === 'in_5_minutes'}
                                    onChange={() => setWhen('in_5_minutes')}
                                    data-test="device-details-reboot-when-5"
                                />
                                5 minutes from now
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="reboot-when"
                                    value="in_10_minutes"
                                    checked={when === 'in_10_minutes'}
                                    onChange={() => setWhen('in_10_minutes')}
                                    data-test="device-details-reboot-when-10"
                                />
                                10 minutes from now
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="reboot-when"
                                    value="at"
                                    checked={when === 'at'}
                                    onChange={() => setWhen('at')}
                                    data-test="device-details-reboot-when-at"
                                />
                                Set date and time
                            </label>
                        </div>
                    </fieldset>

                    {when === 'at' ? (
                        <div className="space-y-2">
                            <Label htmlFor="device-details-reboot-at">Reboot at</Label>
                            <Input
                                id="device-details-reboot-at"
                                type="datetime-local"
                                value={rebootAt}
                                min={minCustomDateTime()}
                                onChange={(event) => setRebootAt(event.target.value)}
                                data-test="device-details-reboot-at"
                            />
                        </div>
                    ) : null}
                </div>

                {submitError ? (
                    <div
                        className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                        data-test="device-details-reboot-error"
                    >
                        {submitError}
                    </div>
                ) : null}

                {results !== null && results.some((result) => !result.ok) ? (
                    <div
                        className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                        data-test="device-details-reboot-results"
                    >
                        <p className="font-medium">Some reboots failed:</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {results
                                .filter((result) => !result.ok)
                                .map((result) => (
                                    <li key={result.serial}>
                                        {result.serial}: {result.error ?? 'Unknown error'}
                                    </li>
                                ))}
                        </ul>
                    </div>
                ) : null}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" disabled={loading}>
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        className="gap-2"
                        disabled={loading || uniqueSerials.length === 0}
                        onClick={() => void handleSubmit()}
                        data-test="device-details-reboot-confirm"
                    >
                        {loading ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                        ) : (
                            <RotateCcw className="size-4" aria-hidden />
                        )}
                        Reboot
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
