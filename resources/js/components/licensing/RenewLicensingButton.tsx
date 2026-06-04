import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { renew } from '@/routes/licensing';

type RenewLicensingButtonProps = {
    licensingSyncedAt?: string | null;
    variant?: 'default' | 'outline' | 'ghost';
    size?: 'default' | 'sm';
    className?: string;
    showSyncedHint?: boolean;
};

function formatSyncedAt(iso: string): string {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

export default function RenewLicensingButton({
    licensingSyncedAt = null,
    variant = 'outline',
    size = 'default',
    className,
    showSyncedHint = false,
}: RenewLicensingButtonProps) {
    const [isRenewing, setIsRenewing] = useState(false);

    const handleRenew = () => {
        setIsRenewing(true);
        router.post(
            renew.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsRenewing(false),
            },
        );
    };

    return (
        <div className={className}>
            <Button
                type="button"
                variant={variant}
                size={size}
                onClick={handleRenew}
                disabled={isRenewing}
                data-test="renew-licensing-button"
            >
                <RefreshCw className={`mr-2 size-4 ${isRenewing ? 'animate-spin' : ''}`} />
                {isRenewing ? 'Renewing…' : 'Renew licensing'}
            </Button>
            {showSyncedHint && licensingSyncedAt ? (
                <p className="mt-1 text-right text-xs text-muted-foreground">
                    Last synced {formatSyncedAt(licensingSyncedAt)}
                </p>
            ) : null}
        </div>
    );
}
