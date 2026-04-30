import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import DeleteModal from '@/components/ui/DeleteModal';
import EditClientModal from '@/components/ui/EditClientModal';
import { testCentralCreds } from '@/actions/App/Http/Controllers/ClientController';
import { current } from '@/routes/clients'
import { type Client } from '@/types/clients/client';

export default function ClientCard({ client, errors, base_urls, isCurrentClient } : { client: Client, errors: Record<string, string>, base_urls: string[], isCurrentClient: boolean }) {
    return (
        <Card key={client.client_id} className={isCurrentClient ? "border-2 border-orange-400 shadow-slate-400" : ""}>
            <CardHeader>
                <CardTitle className="mx-auto">
                    {client.name}
                </CardTitle>
                <CardContent className="mx-auto mt-3 flex flex-wrap justify-center gap-2">
                    <Button
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
                    <div className="flex flex-row flex-nowrap items-center gap-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => router.post(testCentralCreds(client.id).url, { type: 'classic' })}
                        >
                            <Check />
                            Classic
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => router.post(testCentralCreds(client.id).url, { type: 'central' })}
                        >
                            <Check />
                            Check New
                        </Button>
                    </div>
                    <EditClientModal client={client} errors={errors} base_urls={base_urls} />
                    <DeleteModal client={client} />
                </CardContent>
            </CardHeader>
        </Card>
    )
}
