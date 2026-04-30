import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import DeleteModal from '@/components/ui/DeleteModal';
import EditClientModal from '@/components/ui/EditClientModal';
import { testCentralCreds } from '@/actions/App/Http/Controllers/ClientController';
import { current } from '@/routes/clients'
import { type Client } from '@/types/clients/client';

export default function ClientCard({ client, errors, base_urls, isCurrentClient } : { client: Client, errors: Record<string, string>, base_urls: string[], isCurrentClient: boolean }) {
    return (
        <Card key={client.client_id} className={isCurrentClient ? "border-2 border-orange-400 shadow-slate-400" : ""}>
            <CardHeader>
                <CardTitle className="mx-auto flex items-center gap-2">
                    <span>{client.name}</span>
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
                </CardTitle>
                <CardContent className="mx-auto mt-3 flex flex-wrap justify-center gap-2">
                    <div className="flex flex-row flex-nowrap items-center gap-2">
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
                    <EditClientModal client={client} errors={errors} base_urls={base_urls} />
                    <DeleteModal client={client} />
                </CardContent>
            </CardHeader>
        </Card>
    )
}
