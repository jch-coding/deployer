import type { ReactNode } from 'react';
import { Loader2 } from 'lucide-react';
import { useCallback, useState } from 'react';
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
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import { csrfHeaders } from '@/lib/csrf';
import { poeBounce as poeBounceRoute, portBounce as portBounceRoute } from '@/routes/device-details';

export type PortBounceResultRow = {
    port: string;
    status: string;
};

export type PortBounceOutcome =
    | {
          kind: 'completed';
          label: string;
          results: PortBounceResultRow[];
      }
    | {
          kind: 'failed';
          label: string;
          error: string;
      }
    | {
          kind: 'running';
          label: string;
          progressPercent?: number;
      };

type BounceType = 'poe' | 'port';

type SwitchPortBounceDialogProps = {
    serial: string;
    ports: string[];
    type: BounceType;
    trigger: ReactNode;
    disabled?: boolean;
    onOutcome: (outcome: PortBounceOutcome) => void;
};

function bounceLabel(type: BounceType): string {
    return type === 'poe' ? 'PoE Bounce' : 'Port Bounce';
}

export default function SwitchPortBounceDialog({
    serial,
    ports,
    type,
    trigger,
    disabled = false,
    onOutcome,
}: SwitchPortBounceDialogProps) {
    const [open, setOpen] = useState(false);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const runBounce = useCallback(async () => {
        setRunning(true);
        setError(null);
        const label = bounceLabel(type);

        onOutcome({
            kind: 'running',
            label,
        });

        try {
            const startRoute = type === 'poe' ? poeBounceRoute : portBounceRoute;
            const response = await fetch(startRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ serial, ports }),
            });

            const body = (await response.json().catch(() => null)) as {
                task_id?: string;
                error?: string;
            } | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ?? `Failed to start ${label} (HTTP ${response.status}).`,
                );
            }

            const taskId = body?.task_id?.trim();
            if (!taskId) {
                throw new Error('Central did not return a task id.');
            }

            const resultUrl =
                type === 'poe'
                    ? poeBounceRoute.result.url({ serial, taskId })
                    : portBounceRoute.result.url({ serial, taskId });

            const result = await pollCxAsyncOperation(resultUrl, {
                onProgress: (pollBody) => {
                    onOutcome({
                        kind: 'running',
                        label,
                        progressPercent: pollBody.progressPercent,
                    });
                },
            });

            const results = (result.output?.results ?? []).map((row) => ({
                port: row.port ?? '',
                status: row.status ?? '',
            }));

            onOutcome({
                kind: 'completed',
                label,
                results,
            });
            setOpen(false);
        } catch (bounceError) {
            const message =
                bounceError instanceof Error
                    ? bounceError.message
                    : `Failed to run ${label}.`;

            setError(message);
            onOutcome({
                kind: 'failed',
                label,
                error: message,
            });
        } finally {
            setRunning(false);
        }
    }, [onOutcome, ports, serial, type]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!running) {
                    setOpen(next);
                    if (!next) {
                        setError(null);
                    }
                }
            }}
        >
            <DialogTrigger asChild disabled={disabled}>
                {trigger}
            </DialogTrigger>
            <DialogContent data-test={`device-details-${type}-bounce-dialog`}>
                <DialogTitle>{bounceLabel(type)}</DialogTitle>
                <DialogDescription>
                    Run {bounceLabel(type).toLowerCase()} on {ports.length} selected port
                    {ports.length === 1 ? '' : 's'}?
                </DialogDescription>
                <ul
                    className="max-h-40 list-inside list-disc overflow-y-auto text-sm text-muted-foreground"
                    data-test={`device-details-${type}-bounce-port-list`}
                >
                    {ports.map((port) => (
                        <li key={port}>{port}</li>
                    ))}
                </ul>
                {error ? (
                    <p className="text-sm text-destructive" role="alert">
                        {error}
                    </p>
                ) : null}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" disabled={running}>
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={running}
                        onClick={() => void runBounce()}
                        data-test={`device-details-${type}-bounce-confirm`}
                    >
                        {running ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                        ) : null}
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
