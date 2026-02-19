import { Form  } from '@inertiajs/react';
import { toast } from 'sonner';
import { update } from '@/actions/App/Http/Controllers/ClientController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Field, FieldGroup } from '@/components/ui/field';
import { type Client } from '@/types/clients/client';

export default function CreateClientModal( { client, errors, base_urls }: { client: Client, errors: Record<string, string>, base_urls: string[] }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                >
                    Edit Client
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Edit Client
                    </DialogTitle>
                    <DialogDescription>
                        Edit a Client
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={ update(client.id) }
                    method='PUT'
                    transform={ data => Object.fromEntries(Object.entries(data).filter(([, v]) => v != "")) }
                    onSuccess={ () => toast.success('Client edited successfully')}
                    className="block space-y-4">
                    <FieldGroup>
                        <Field>
                            <label htmlFor="name" className="font-bold">Name</label>
                            <input name="name" placeholder={ client.name } />
                            { errors.name && <p className="text-red-500 text-xs">{ errors.name }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="client_id" className="font-bold">Client ID</label>
                            <input name="client_id"  placeholder={ client.client_id } />
                            { errors.client_id && <p className="text-red-500 text-xs">{ errors.client_id }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="client_secret" className="font-bold">Client Secret</label>
                            <input name="client_secret"  placeholder="***********" />
                            { errors.client_secret && <p className="text-red-500 text-xs">{ errors.client_secret }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="customer_id" className="font-bold">Customer ID</label>
                            <input name="customer_id"  placeholder={client.customer_id} />
                            { errors.customer_id && <p className="text-red-500 text-xs">{ errors.customer_id }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="base_url" className="font-bold">Base URL</label>
                            <select name="base_url" id="base_url">
                                { base_urls.map((url) =>
                                    <option key={url} value={url} selected={`https://${url}.api.central.arubanetworks.com/` == client.base_url}>{url}</option>)
                                }
                            </select>
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button type="submit">
                            Edit Client
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    )
}
