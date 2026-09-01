import { csrfHeaders } from '@/lib/csrf';

export type CxAsyncPollResponse = {
    status?: string;
    progressPercent?: number;
    failReason?: string | null;
    output?: {
        results?: Array<{
            port?: string;
            status?: string;
            command?: string;
            output?: string;
        }>;
    } | null;
    error?: string;
};

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

export async function pollCxAsyncOperation(
    resultUrl: string,
    options?: {
        intervalMs?: number;
        maxAttempts?: number;
        onProgress?: (body: CxAsyncPollResponse) => void;
    },
): Promise<CxAsyncPollResponse> {
    const intervalMs = options?.intervalMs ?? 1500;
    const maxAttempts = options?.maxAttempts ?? 80;

    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        const response = await fetch(resultUrl, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            credentials: 'same-origin',
        });

        const body = (await response.json().catch(() => null)) as CxAsyncPollResponse | null;

        if (!response.ok) {
            throw new Error(
                body?.error ?? `Failed to fetch operation results (HTTP ${response.status}).`,
            );
        }

        if (body === null) {
            throw new Error('Failed to fetch operation results: empty response.');
        }

        options?.onProgress?.(body);

        const status = (body.status ?? '').toUpperCase();

        if (status === 'COMPLETED') {
            return body;
        }

        if (status === 'FAILED') {
            throw new Error(body.failReason ?? 'Operation failed.');
        }

        await sleep(intervalMs);
    }

    throw new Error('Timed out waiting for operation results.');
}
