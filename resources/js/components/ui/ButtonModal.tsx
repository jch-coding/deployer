import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import type { Client } from '@/types/clients/client';

export function ButtonModal( { dialogTriggerText, dialogTitle, dialogDescription, children }: { dialogTriggerText: string, dialogTitle: string, dialogDescription: string } & React.PropsWithChildren ) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                >
                    { dialogTriggerText }
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        { dialogTitle }
                    </DialogTitle>
                    <DialogDescription>
                        { dialogDescription }
                    </DialogDescription>
                </DialogHeader>
                { children }
            </DialogContent>
        </Dialog>
    )
}
