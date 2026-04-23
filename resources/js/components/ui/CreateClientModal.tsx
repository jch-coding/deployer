import { Form, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Field, FieldGroup } from '@/components/ui/field';
import { store } from '@/routes/clients';

export default function CreateClientModal( {  errors, base_urls }: { errors: Record<string, string>, base_urls: string[] } ) {
    const { resetAndClearErrors } = useForm();
    const dialogCloseRef = useRef<HTMLButtonElement>(null);
    const [ saveSuccess, setSaveSuccess ] = useState(false);
    useEffect(
        () => {
            if (saveSuccess) {
                dialogCloseRef.current?.click();
            }
    });
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    className="absolute top-3 right-3 hover:bg-slate-300"
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
                    onSuccess={ () => {
                        toast.success('Client saved successfully');
                        setSaveSuccess(true);
                        }
                    }
                    onError={ () => {
                        toast.error('Failed to save client');
                    }}
                    onAbort={() => resetAndClearErrors()}
                    className="block space-y-4">
                    {({ processing }) => (
                        <>
                    <FieldGroup>
                        {
                            errors.failed_to_get_token && <p className="text-red-500 text-xs">{ errors.failed_to_get_token }</p>
                        }
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
                    <FieldGroup>
                        <Field>
                            <label htmlFor="classic_client_id" className="font-bold">Classic Client ID</label>
                            <input name="classic_client_id" id="classic_client_id" type="text" placeholder="optional" className="input input-bordered w-full" />
                            { errors.classic_client_id && <span className="text-error">{errors.classic_client_id}</span>}
                        </Field>
                        <Field>
                            <label htmlFor="classic_client_secret" className="font-bold">Classic Client Secret</label>
                            <input name="classic_client_secret" id="classic_client_secret" type="text" placeholder="optional" className="input input-bordered w-full" />
                            { errors.classic_client_secret && <span className="text-error">{errors.classic_client_secret}</span>}
                        </Field>
                        <Field>
                            <label htmlFor="classic_username" className="font-bold">Classic Username</label>
                            <input name="classic_username" id="classic_username" type="text" placeholder="optional" className="input input-bordered w-full" />
                            { errors.classic_username && <span className="text-error">{errors.classic_username}</span>}
                        </Field>
                        <Field>
                            <label htmlFor="classic_password" className="font-bold">Classic Password</label>
                            <input name="classic_password" id="classic_password" type="password" placeholder="optional" className="input input-bordered w-full" />
                            { errors.classic_password && <span className="text-error">{errors.classic_password}</span>}
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button ref={dialogCloseRef} variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            Add Client
                        </Button>
                    </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
        )
}
