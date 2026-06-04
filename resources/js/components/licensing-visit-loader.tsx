import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Spinner } from '@/components/ui/spinner';

function isLicensingInventoryVisit(url: string | URL, method: string): boolean {
    if (method.toLowerCase() !== 'get') {
        return false;
    }

    const pathname =
        typeof url === 'string'
            ? new URL(url, window.location.origin).pathname
            : url.pathname;

    return pathname === '/licensing' || pathname.endsWith('/licensing');
}

export function LicensingVisitLoader() {
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const stopLoading = () => setLoading(false);

        const removeStartListener = router.on('start', (event) => {
            const visit = event.detail.visit;
            if (isLicensingInventoryVisit(visit.url, visit.method)) {
                setLoading(true);
            }
        });

        const removeFinishListener = router.on('finish', stopLoading);
        const removeCancelListener = router.on('cancel', stopLoading);
        const removeErrorListener = router.on('error', stopLoading);

        return () => {
            removeStartListener();
            removeFinishListener();
            removeCancelListener();
            removeErrorListener();
        };
    }, []);

    if (!loading) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-background/80 backdrop-blur-sm"
            role="status"
            aria-live="polite"
            aria-busy="true"
        >
            <div className="flex flex-col items-center gap-3 rounded-lg border bg-card px-8 py-6 shadow-lg">
                <Spinner className="size-8" />
                <p className="text-sm font-medium">Loading licensing inventory from Central…</p>
                <p className="max-w-sm text-center text-xs text-muted-foreground">
                    Fetching device inventory, subscriptions, and enabled services. This may take a
                    moment for large accounts.
                </p>
            </div>
        </div>
    );
}
