/**
 * Headers required for same-origin JSON requests to Laravel web routes.
 * Prefers the csrf-token meta tag; falls back to the XSRF-TOKEN cookie.
 */
export function csrfHeaders(): Record<string, string> {
    const fromMeta = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content?.trim();

    if (fromMeta) {
        return { 'X-CSRF-TOKEN': fromMeta };
    }

    const xsrf = readCookie('XSRF-TOKEN');

    if (xsrf) {
        return { 'X-XSRF-TOKEN': xsrf };
    }

    return {};
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}
