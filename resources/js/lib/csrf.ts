/**
 * Headers required for same-origin JSON requests to Laravel web routes.
 * Prefers the XSRF-TOKEN cookie (refreshed on every response) over the
 * csrf-token meta tag (only set on full page load).
 */
export function csrfHeaders(): Record<string, string> {
    const xsrf = readCookie('XSRF-TOKEN');

    if (xsrf) {
        return { 'X-XSRF-TOKEN': xsrf };
    }

    const fromMeta = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content?.trim();

    if (fromMeta) {
        return { 'X-CSRF-TOKEN': fromMeta };
    }

    return {};
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}
