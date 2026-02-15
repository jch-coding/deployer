import { Form } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { edit, store } from '@/routes/clients';
import type { Client } from '@/types/clients/client';

export function ClientForm({ client,
                               successMessage,
                               formMethod,
                               submitText,
                               errors,
                               base_urls }:
                           { client?: Client,
                               successMessage: string,
                               formMethod: string,
                               submitText: string,
                               errors: Record<string, string>,
                               base_urls: string[] }) {
    const onSuccess = () => toast.success(successMessage)

    return (
        <Form
            action={
            formMethod === 'PUT' ?
            edit.url(client.id) :
            store.url()}
            method={ formMethod }
            transform={
            formMethod === 'PUT' ?
            data =>
                Object.fromEntries(Object.entries(data).filter(([, v]) => v != ""))
                : data => data
            }
            onSuccess={ onSuccess }
            className="block space-y-4">
            <FieldGroup>
                <Field>
                    <label htmlFor="name" className="font-bold">Name</label>
                    <input name="name" placeholder={ client ? client.name : ''} required={ formMethod === 'POST'} />
                    { errors.name && <p className="text-red-500 text-xs">{ errors.name }</p> }
                </Field>
                <Field>
                    <label htmlFor="client_id" className="font-bold">Client ID</label>
                    <input name="client_id" placeholder={ client ? client.client_id : ''} required={ formMethod === 'POST'} />
                    { errors.client_id && <p className="text-red-500 text-xs">{ errors.client_id }</p> }
                </Field>
                <Field>
                    <label htmlFor="client_secret" className="font-bold">Client Secret</label>
                    <input name="client_secret" placeholder="***********" required={ formMethod === 'POST'}/>
                    { errors.client_secret && <p className="text-red-500 text-xs">{ errors.client_secret }</p> }
                </Field>
                <Field>
                    <label htmlFor="customer_id" className="font-bold">Customer ID</label>
                    <input name="customer_id" placeholder={client ? client.customer_id : ''} required={ formMethod === 'POST'}/>
                    { errors.customer_id && <p className="text-red-500 text-xs">{ errors.customer_id }</p> }
                </Field>
                <Field>
                    <label htmlFor="base_url" className="font-bold">Base URL</label>
                    <select name="base_url" id="base_url">
                        { base_urls.map((url) =>
                            <option key={url} value={url} selected={url === client?.base_url}>{url}</option>)
                        }
                    </select>
                </Field>
            </FieldGroup>
            <Button type="submit"
            >
                { submitText }
            </Button>
        </Form>
    )
}
