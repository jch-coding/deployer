import { Loader2, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
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

type RebootProgress = {
    start: number;
    end: number;
    total: number;
};

const REBOOT_BATCH_SIZE = 25;

function chunkSerials(serials: string[], size: number): string[][] {
    const chunks: string[][] = [];

    for (let index = 0; index < serials.length; index += size) {
        chunks.push(serials.slice(index, index + size));
    }

    return chunks;
}

function failedResultsForChunk(
    serials: string[],
    error: string,
): RebootResult[] {
    return serials.map((serial) => ({
        serial,
        ok: false,
        error,
        status: null,
    }));
}

function rebootFailureMessage(
    payload: RebootResponse | null,
    status: number,
): string {
    const validationMessage = payload?.errors
        ? Object.values(payload.errors).flat().join(' ')
        : null;

    return (
        payload?.error ??
        validationMessage ??
        payload?.message ??
        `Failed to schedule reboot (HTTP ${status}).`
    );
}

async function postRebootChunk(
    serials: string[],
    when: RebootWhen,
    rebootAtIso?: string,
): Promise<{ payload: RebootResponse } | { error: string }> {
    const body: {
        serials: string[];
        when: RebootWhen;
        reboot_at?: string;
    } = {
        serials,
        when,
    };

    if (rebootAtIso !== undefined) {
        body.reboot_at = rebootAtIso;
    }

    try {
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

        const payload = (await response
            .json()
            .catch(() => null)) as RebootResponse | null;

        if (!response.ok) {
            return { error: rebootFailureMessage(payload, response.status) };
        }

        if (payload === null) {
            return { error: 'Failed to schedule reboot: empty response.' };
        }

        if (payload.error) {
            return { error: payload.error };
        }

        return { payload };
    } catch (error) {
        return {
            error:
                error instanceof Error
                    ? error.message
                    : 'Failed to schedule reboot.',
        };
    }
}

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
    const [progress, setProgress] = useState<RebootProgress | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [results, setResults] = useState<RebootResult[] | null>(null);

    const uniqueSerials = useMemo(
        () => [
            ...new Set(
                serials
                    .map((serial) => serial.trim())
                    .filter((serial) => serial !== ''),
            ),
        ],
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
        setProgress(null);
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

        let rebootAtIso: string | undefined;

        if (when === 'at') {
            const parsed = new Date(rebootAt);
            if (Number.isNaN(parsed.getTime())) {
                setSubmitError('Select a valid date and time for the reboot.');
                return;
            }

            if (parsed.getTime() <= Date.now()) {
                setSubmitError('Reboot time must be in the future.');
                return;
            }

            rebootAtIso = parsed.toISOString();
        }

        const chunks = chunkSerials(uniqueSerials, REBOOT_BATCH_SIZE);

        setLoading(true);
        setSubmitError(null);
        setResults(null);
        setProgress(null);

        try {
            const rebootResults: RebootResult[] = [];
            let scheduledCount = 0;
            let scheduledAt: string | null = null;

            for (let index = 0; index < chunks.length; index++) {
                const chunk = chunks[index];
                if (chunk === undefined || chunk.length === 0) {
                    continue;
                }

                if (chunks.length > 1) {
                    const start = index * REBOOT_BATCH_SIZE + 1;
                    setProgress({
                        start,
                        end: start + chunk.length - 1,
                        total: uniqueSerials.length,
                    });
                }

                const outcome = await postRebootChunk(chunk, when, rebootAtIso);

                if ('error' in outcome) {
                    rebootResults.push(
                        ...failedResultsForChunk(chunk, outcome.error),
                    );
                    continue;
                }

                if (outcome.payload.scheduled) {
                    scheduledCount += chunk.length;
                    scheduledAt = outcome.payload.scheduled_at ?? scheduledAt;
                    continue;
                }

                rebootResults.push(...(outcome.payload.results ?? []));
            }

            if (scheduledCount === uniqueSerials.length) {
                const scheduledLabel = scheduledAt
                    ? formatScheduledAt(scheduledAt)
                    : 'the selected time';

                toast.success(
                    `Reboot scheduled for ${uniqueSerials.length} access point${uniqueSerials.length === 1 ? '' : 's'} at ${scheduledLabel}.`,
                );
                setOpen(false);
                resetForm();

                return;
            }

            if (scheduledCount > 0) {
                const failureCount = uniqueSerials.length - scheduledCount;
                const scheduledLabel = scheduledAt
                    ? formatScheduledAt(scheduledAt)
                    : 'the selected time';

                toast.warning(
                    `Reboot scheduled for ${scheduledCount} access point${scheduledCount === 1 ? '' : 's'} at ${scheduledLabel}, but ${failureCount} failed.`,
                );
                setResults(rebootResults);
                setSubmitError(
                    `Failed to schedule reboot for ${failureCount} access point${failureCount === 1 ? '' : 's'}.`,
                );

                return;
            }

            setResults(rebootResults);

            const successCount = rebootResults.filter(
                (result) => result.ok,
            ).length;
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
                toast.error(
                    `Failed to reboot ${failureCount} access point${failureCount === 1 ? '' : 's'}.`,
                );
            }
        } catch (error) {
            setSubmitError(
                error instanceof Error
                    ? error.message
                    : 'Failed to schedule reboot.',
            );
        } finally {
            setLoading(false);
            setProgress(null);
        }
    }, [rebootAt, resetForm, uniqueSerials, when]);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent data-test="device-details-reboot-dialog">
                <DialogTitle>
                    Reboot access point{uniqueSerials.length === 1 ? '' : 's'}
                </DialogTitle>
                <DialogDescription>
                    This will reboot {deviceLabel}. Connected clients will be
                    disconnected during the reboot.
                </DialogDescription>

                <div className="space-y-3">
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            When should the reboot happen?
                        </legend>
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
                            <Label htmlFor="device-details-reboot-at">
                                Reboot at
                            </Label>
                            <Input
                                id="device-details-reboot-at"
                                type="datetime-local"
                                value={rebootAt}
                                min={minCustomDateTime()}
                                onChange={(event) =>
                                    setRebootAt(event.target.value)
                                }
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
                                        {result.serial}:{' '}
                                        {result.error ?? 'Unknown error'}
                                    </li>
                                ))}
                        </ul>
                    </div>
                ) : null}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={loading}
                        >
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
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden
                            />
                        ) : (
                            <RotateCcw className="size-4" aria-hidden />
                        )}
                        {loading && progress ? (
                            <span data-test="device-details-reboot-progress">
                                Rebooting {progress.start}–{progress.end} of{' '}
                                {progress.total}
                            </span>
                        ) : (
                            'Reboot'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
