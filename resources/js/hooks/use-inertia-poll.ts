import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Periodically partial-reloads Inertia props. Useful as a fallback when
 * Echo/Reverb WebSockets are unavailable (e.g. HTTPS tunnel + local Reverb).
 */
export function useInertiaPoll(
    only: readonly string[],
    intervalMs = 5000,
    enabled = true,
): void {
    const onlyKey = only.join(',');

    useEffect(() => {
        if (!enabled || onlyKey.length === 0 || intervalMs <= 0) {
            return;
        }

        const props = onlyKey.split(',').filter(Boolean);

        const id = window.setInterval(() => {
            router.reload({
                only: props,
                preserveScroll: true,
                preserveState: true,
            });
        }, intervalMs);

        return () => window.clearInterval(id);
    }, [enabled, intervalMs, onlyKey]);
}
