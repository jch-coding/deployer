import { router } from '@inertiajs/react';
import { ChevronDown, KeyRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { update, testCentralCreds } from '@/actions/App/Http/Controllers/ClientController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import DeleteModal from '@/components/ui/DeleteModal';
import EditClientModal from '@/components/ui/EditClientModal';
import { cn } from '@/lib/utils';
import { current } from '@/routes/clients'
import { type Client } from '@/types/clients/client';

export default function ClientCard({ client, errors, base_urls, isCurrentClient } : { client: Client, errors: Record<string, string>, base_urls: string[], isCurrentClient: boolean }) {
    const [tokensOpen, setTokensOpen] = useState(false);
    const [classicClientId, setClassicClientId] = useState(client.classic_client_id ?? '');
    const [classicRefreshToken, setClassicRefreshToken] = useState('');
    const [classicAccessToken, setClassicAccessToken] = useState('');
    const [savingTokens, setSavingTokens] = useState(false);

    useEffect(() => {
        setClassicClientId(client.classic_client_id ?? '');
    }, [client.classic_client_id]);

    const classicClientIdChanged = classicClientId.trim() !== (client.classic_client_id ?? '');

    const saveClassicTokens = () => {
        const clientId = classicClientId.trim();
        const refreshToken = classicRefreshToken.trim();
        const accessToken = classicAccessToken.trim();

        if (!classicClientIdChanged && !refreshToken && !accessToken) {
            return;
        }

        setSavingTokens(true);
        router.put(
            update(client.id).url,
            {
                ...(classicClientIdChanged ? { classic_client_id: clientId } : {}),
                ...(refreshToken ? { classic_refresh_token: refreshToken } : {}),
                ...(accessToken ? { classic_access_token: accessToken } : {}),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingTokens(false);
                    setClassicRefreshToken('');
                    setClassicAccessToken('');
                },
            },
        );
    };

    const canSaveTokens = classicClientIdChanged
        || classicRefreshToken.trim().length > 0
        || classicAccessToken.trim().length > 0;

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
                    <Collapsible open={tokensOpen} onOpenChange={setTokensOpen}>
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                className="w-full justify-between"
                                aria-expanded={tokensOpen}
                            >
                                <span>Classic Central tokens</span>
                                <ChevronDown
                                    className={cn(
                                        'size-4 shrink-0 transition-transform',
                                        tokensOpen && 'rotate-180',
                                    )}
                                    aria-hidden
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="mt-3 space-y-3">
                            <div className="w-full space-y-1">
                                <label htmlFor={`classic-client-id-${client.id}`} className="text-sm font-medium">
                                    Client ID
                                </label>
                                <Input
                                    id={`classic-client-id-${client.id}`}
                                    type="text"
                                    autoComplete="off"
                                    value={classicClientId}
                                    onChange={(e) => setClassicClientId(e.target.value)}
                                />
                                {errors.classic_client_id && (
                                    <p className="text-red-500 text-xs">{errors.classic_client_id}</p>
                                )}
                            </div>
                            <div className="w-full space-y-1">
                                <label htmlFor={`classic-refresh-token-${client.id}`} className="text-sm font-medium">
                                    Refresh token
                                </label>
                                <Input
                                    id={`classic-refresh-token-${client.id}`}
                                    type="password"
                                    autoComplete="off"
                                    placeholder={client.has_classic_refresh_token ? '••••••••' : ''}
                                    value={classicRefreshToken}
                                    onChange={(e) => setClassicRefreshToken(e.target.value)}
                                />
                                {errors.classic_refresh_token && (
                                    <p className="text-red-500 text-xs">{errors.classic_refresh_token}</p>
                                )}
                            </div>
                            <div className="w-full space-y-1">
                                <label htmlFor={`classic-access-token-${client.id}`} className="text-sm font-medium">
                                    Access token
                                </label>
                                <Input
                                    id={`classic-access-token-${client.id}`}
                                    type="password"
                                    autoComplete="off"
                                    placeholder={client.has_classic_access_token ? '••••••••' : ''}
                                    value={classicAccessToken}
                                    onChange={(e) => setClassicAccessToken(e.target.value)}
                                />
                                {errors.classic_access_token && (
                                    <p className="text-red-500 text-xs">{errors.classic_access_token}</p>
                                )}
                            </div>
                            {client.classic_expires_in && (
                                <p className="text-muted-foreground text-xs">
                                    Classic token expires {new Date(client.classic_expires_in).toLocaleString()}
                                </p>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                className="w-full"
                                disabled={savingTokens || !canSaveTokens}
                                onClick={saveClassicTokens}
                            >
                                Save
                            </Button>
                        </CollapsibleContent>
                    </Collapsible>
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
