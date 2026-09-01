import { Loader2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { csrfHeaders } from '@/lib/csrf';
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import { cn } from '@/lib/utils';
import { showCommands as showCommandsRoute } from '@/routes/device-details';
import { result as apShowCommandsResultRoute } from '@/routes/device-details/ap-show-commands';
import { result as showCommandsResultRoute } from '@/routes/device-details/show-commands';

const SHOW_COMMAND_PATTERN = /^\s*show\b.+/i;
const MAX_COMMANDS = 20;

type ShowCommandResultItem = {
    command: string;
    output: string;
};

type ResultBlock =
    | {
          id: string;
          kind: 'pending';
          commands: string[];
          progressPercent?: number;
      }
    | {
          id: string;
          kind: 'completed';
          results: ShowCommandResultItem[];
      }
    | {
          id: string;
          kind: 'failed';
          commands: string[];
          error: string;
      };

type SwitchShowCommandsCardProps = {
    serial: string;
    onClose: () => void;
    deviceType?: 'ACCESS_POINT';
};

function parseCommands(input: string): string[] {
    return input
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

function validateCommands(commands: string[]): string | null {
    if (commands.length === 0) {
        return 'Enter at least one show command.';
    }

    if (commands.length > MAX_COMMANDS) {
        return `Too many commands. Maximum allowed is ${MAX_COMMANDS}.`;
    }

    const invalid = commands.find((command) => !SHOW_COMMAND_PATTERN.test(command));
    if (invalid) {
        return "Each command must start with 'show'.";
    }

    return null;
}

export default function SwitchShowCommandsCard({
    serial,
    onClose,
    deviceType,
}: SwitchShowCommandsCardProps) {
    const isAccessPoint = deviceType === 'ACCESS_POINT';

    const [commandInput, setCommandInput] = useState('');
    const [blocks, setBlocks] = useState<ResultBlock[]>([]);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const blockIdRef = useRef(0);

    const nextBlockId = useCallback(() => {
        blockIdRef.current += 1;

        return `block-${blockIdRef.current}`;
    }, []);

    useEffect(() => {
        const container = scrollContainerRef.current;
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }, [blocks]);

    const pollForResults = useCallback(
        async (taskId: string, blockId: string, commands: string[]) => {
            const body = await pollCxAsyncOperation(
                (isAccessPoint ? apShowCommandsResultRoute : showCommandsResultRoute).url({
                    serial,
                    taskId,
                }),
                {
                    onProgress: (pollBody) => {
                        setBlocks((current) =>
                            current.map((block) =>
                                block.id === blockId && block.kind === 'pending'
                                    ? {
                                          ...block,
                                          progressPercent: pollBody.progressPercent,
                                      }
                                    : block,
                            ),
                        );
                    },
                },
            );

            const results = body.output?.results ?? commands.map((command) => ({
                command,
                output: '',
            }));

            setBlocks((current) =>
                current.map((block) =>
                    block.id === blockId
                        ? { id: blockId, kind: 'completed', results }
                        : block,
                ),
            );
        },
        [isAccessPoint, serial],
    );

    const runCommands = useCallback(async () => {
        const commands = parseCommands(commandInput);
        const validationError = validateCommands(commands);

        if (validationError) {
            setSubmitError(validationError);

            return;
        }

        setSubmitError(null);
        setRunning(true);

        const blockId = nextBlockId();
        setBlocks((current) => [
            ...current,
            {
                id: blockId,
                kind: 'pending',
                commands,
            },
        ]);
        setCommandInput('');

        try {
            const response = await fetch(showCommandsRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    serial,
                    commands,
                    ...(isAccessPoint ? { device_type: 'ACCESS_POINT' } : {}),
                }),
            });

            const body = (await response.json().catch(() => null)) as {
                task_id?: string;
                error?: string;
            } | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ?? `Failed to start show commands (HTTP ${response.status}).`,
                );
            }

            const taskId = body?.task_id?.trim();
            if (!taskId) {
                throw new Error('Central did not return a task id.');
            }

            await pollForResults(taskId, blockId, commands);
        } catch (error) {
            setBlocks((current) =>
                current.map((block) =>
                    block.id === blockId
                        ? {
                              id: blockId,
                              kind: 'failed',
                              commands,
                              error:
                                  error instanceof Error
                                      ? error.message
                                      : 'Failed to run show commands.',
                          }
                        : block,
                ),
            );
        } finally {
            setRunning(false);
        }
    }, [commandInput, isAccessPoint, nextBlockId, pollForResults, serial]);

    const handleClose = () => {
        setBlocks([]);
        setCommandInput('');
        setSubmitError(null);
        onClose();
    };

    return (
        <Card className="mt-4 gap-0 py-0" data-test="device-details-show-commands-card">
            <div
                ref={scrollContainerRef}
                className="max-h-[32rem] overflow-y-auto"
            >
                <div className="sticky top-0 z-10 border-b border-border bg-card px-6 py-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">Troubleshooting</h3>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={handleClose}
                            aria-label="Close troubleshooting"
                            data-test="device-details-show-commands-close"
                        >
                            <X className="size-4" aria-hidden />
                        </Button>
                    </div>
                    <textarea
                        value={commandInput}
                        onChange={(event) => setCommandInput(event.target.value)}
                        placeholder={'show version\nshow interface brief'}
                        rows={3}
                        disabled={running}
                        className={cn(
                            'border-input placeholder:text-muted-foreground flex min-h-[4.5rem] w-full resize-y rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none',
                            'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                            'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                        )}
                        data-test="device-details-show-commands-input"
                    />
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            className="gap-2"
                            disabled={running}
                            onClick={() => void runCommands()}
                            data-test="device-details-show-commands-run"
                        >
                            {running ? (
                                <Loader2 className="size-4 animate-spin" aria-hidden />
                            ) : null}
                            Run
                        </Button>
                        <span className="text-xs text-muted-foreground">
                            One show command per line (max {MAX_COMMANDS})
                        </span>
                    </div>
                    {submitError ? (
                        <p
                            className="mt-3 text-sm text-destructive"
                            role="alert"
                            data-test="device-details-show-commands-submit-error"
                        >
                            {submitError}
                        </p>
                    ) : null}
                </div>

                <CardContent className="px-6 py-4">
                    {blocks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Run show commands to see output here.
                        </p>
                    ) : (
                        <div className="space-y-6">
                            {blocks.map((block) => (
                                <div key={block.id} className="space-y-3">
                                    {block.kind === 'pending' ? (
                                        <>
                                            {block.commands.map((command) => (
                                                <p
                                                    key={`${block.id}-${command}`}
                                                    className="font-mono text-sm text-foreground"
                                                >
                                                    &gt; {command}
                                                </p>
                                            ))}
                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <Loader2 className="size-4 animate-spin" aria-hidden />
                                                Running
                                                {typeof block.progressPercent === 'number'
                                                    ? ` (${block.progressPercent}%)`
                                                    : '…'}
                                            </div>
                                        </>
                                    ) : null}

                                    {block.kind === 'completed'
                                        ? block.results.map((result) => (
                                              <div key={`${block.id}-${result.command}`} className="space-y-1">
                                                  <p className="font-mono text-sm text-foreground">
                                                      &gt; {result.command}
                                                  </p>
                                                  <pre className="font-mono text-sm whitespace-pre-wrap text-muted-foreground">
                                                      {result.output || '(no output)'}
                                                  </pre>
                                              </div>
                                          ))
                                        : null}

                                    {block.kind === 'failed' ? (
                                        <>
                                            {block.commands.map((command) => (
                                                <p
                                                    key={`${block.id}-${command}`}
                                                    className="font-mono text-sm text-foreground"
                                                >
                                                    &gt; {command}
                                                </p>
                                            ))}
                                            <p
                                                className="text-sm text-destructive"
                                                role="alert"
                                                data-test="device-details-show-commands-error"
                                            >
                                                {block.error}
                                            </p>
                                        </>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </div>
        </Card>
    );
}
