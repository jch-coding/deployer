import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import DeleteModal from '@/components/ui/DeleteModal';
import EditClientModal from '@/components/ui/EditClientModal';
import { current } from '@/routes/clients'
import { type Client } from '@/types/clients/client';

export default function ClientCard({ client, errors, base_urls, isCurrentClient } : { client: Client, errors: Record<string, string>, base_urls: string[], isCurrentClient: boolean }) {
    return (
        <Card key={client.client_id} className={isCurrentClient ? "border-2 border-orange-400 shadow-slate-400" : ""}>
            <CardHeader>
                <CardTitle className="mx-auto">
                    {client.name}
                </CardTitle>
                <CardContent className="mx-auto mt-3 flex justify-items-center gap-2">
                    <Button onClick={() => router.put(current(client.id))} disabled={ isCurrentClient }>
                        Set Current
                    </Button>
                    <EditClientModal client={client} errors={errors} base_urls={base_urls} />
                    <DeleteModal client={client} />
                </CardContent>
            </CardHeader>
        </Card>
    )
}
