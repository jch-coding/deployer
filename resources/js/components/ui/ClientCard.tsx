import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { update, testCentralCreds } from '@/actions/App/Http/Controllers/ClientController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import DeleteModal from '@/components/ui/DeleteModal';
import EditClientModal from '@/components/ui/EditClientModal';
import { current } from '@/routes/clients'
import { type Client } from '@/types/clients/client';

export default function ClientCard({ client, errors, base_urls, isCurrentClient } : { client: Client, errors: Record<string, string>, base_urls: string[], isCurrentClient: boolean }) {
    const [classicRefreshToken, setClassicRefreshToken] = useState('');
    const [savingRefreshToken, setSavingRefreshToken] = useState(false);

    const saveClassicRefreshToken = () => {
        if (!classicRefreshToken.trim()) {
            return;
        }
        setSavingRefreshToken(true);
        router.put(
            update(client.id).url,
            { classic_refresh_token: classicRefreshToken },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingRefreshToken(false);
                    setClassicRefreshToken('');
                },
            },
        );
    };

    return (
        <Card key={client.client_id} className={isCurrentClient ? "border-2 border-orange-400 shadow-slate-400" : ""}>
            <CardHeader>
                <CardTitle className="mx-auto text-center text-xl font-bold">
                    {client.name}
                </CardTitle>
                <CardContent className="mx-auto mt-3 flex w-full flex-col items-stretch gap-3">
                    <div className="flex flex-row flex-nowrap items-center justify-center gap-2">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.post(testCentralCreds(client.id).url, { type: 'classic' })}
                                >
                                    <KeyRound />
                                    Classic
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                Verify Classic Central credentials (legacy client ID, secret, and user login) for this client.
                            </TooltipContent>
                        </Tooltip>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.post(testCentralCreds(client.id).url, { type: 'central' })}
                                >
                                    <KeyRound />
                                    New
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                Verify Central credentials (client ID and secret) for this client.
                            </TooltipContent>
                        </Tooltip>
                    </div>
                    <div className="w-full space-y-1">
                        <label htmlFor={`classic-refresh-token-${client.id}`} className="text-sm font-medium">
                            Classic refresh token
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id={`classic-refresh-token-${client.id}`}
                                type="password"
                                autoComplete="off"
                                placeholder={client.has_classic_refresh_token ? '••••••••' : ''}
                                value={classicRefreshToken}
                                onChange={(e) => setClassicRefreshToken(e.target.value)}
                                className="flex-1"
                            />
                            <Button
                                type="button"
                                size="sm"
                                disabled={savingRefreshToken || !classicRefreshToken.trim()}
                                onClick={saveClassicRefreshToken}
                            >
                                Save
                            </Button>
                        </div>
                        {errors.classic_refresh_token && (
                            <p className="text-red-500 text-xs">{errors.classic_refresh_token}</p>
                        )}
                        {client.classic_expires_in && (
                            <p className="text-muted-foreground text-xs">
                                Classic token expires {new Date(client.classic_expires_in).toLocaleString()}
                            </p>
                        )}
                    </div>
                    <div className="flex w-full justify-between gap-2">
                        <Button
                            size="sm"
                            className="rounded-full"
                            onClick={() =>
                                router.put(current(client.id), {}, {
                                    onSuccess: () => {
                                        router.flushAll();
                                    },
                                })
                            }
                            disabled={isCurrentClient}
                        >
                            Set Current
                        </Button>
                        <EditClientModal client={client} errors={errors} base_urls={base_urls} />
                        <DeleteModal client={client} />
                    </div>
                </CardContent>
            </CardHeader>
        </Card>
    )
}
