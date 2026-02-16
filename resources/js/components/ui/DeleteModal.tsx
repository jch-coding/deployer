import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { destroy } from '@/routes/clients';
import { type Client } from '@/types/clients/client';

export default function DeleteModal( { client } : { client: Client }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="destructive"
                    className="hover:bg-red-400"
                >
                    Delete
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Delete Client?
                    </DialogTitle>
                    <DialogDescription>
                        This action cannot be
                        undone. Are you sure you
                        want to delete this
                        client?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() => {
                            router.delete(
                                destroy.url(client.id),
                            );
                        }}
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    )
}
