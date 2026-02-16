import { Form } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Field, FieldGroup } from '@/components/ui/field';
import { store } from '@/routes/clients';

export default function CreateClientModal( {  errors, base_urls }: { errors: Record<string, string>, base_urls: string[] } ) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                >
                    Add Client
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Add Client
                    </DialogTitle>
                    <DialogDescription>
                        Add a Client
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={ store.url() }
                    method='POST'
                    onSuccess={ () => toast.success('Client saved successfully')}
                    className="block space-y-4">
                    <FieldGroup>
                        <Field>
                            <label htmlFor="name" className="font-bold">Name</label>
                            <input name="name" required />
                            { errors.name && <p className="text-red-500 text-xs">{ errors.name }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="client_id" className="font-bold">Client ID</label>
                            <input name="client_id"  required />
                            { errors.client_id && <p className="text-red-500 text-xs">{ errors.client_id }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="client_secret" className="font-bold">Client Secret</label>
                            <input name="client_secret"  required />
                            { errors.client_secret && <p className="text-red-500 text-xs">{ errors.client_secret }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="customer_id" className="font-bold">Customer ID</label>
                            <input name="customer_id"  required />
                            { errors.customer_id && <p className="text-red-500 text-xs">{ errors.customer_id }</p> }
                        </Field>
                        <Field>
                            <label htmlFor="base_url" className="font-bold">Base URL</label>
                            <select name="base_url" id="base_url">
                                { base_urls.map((url) =>
                                    <option key={url} value={url} >{url}</option>)
                                }
                            </select>
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button type="submit">
                            Add Client
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
        )
}
