/**
 * GreenLake subscription tags are key-value pairs. Display tag keys, not values.
 */
export function subscriptionTagKeys(tags: unknown): string[] {
    if (tags === null || tags === undefined) {
        return [];
    }

    if (Array.isArray(tags)) {
        const keys: string[] = [];
        for (const tag of tags) {
            if (typeof tag === 'string' && tag.trim() !== '') {
                keys.push(tag.trim());
                continue;
            }
            if (tag !== null && typeof tag === 'object' && 'key' in tag) {
                const key = String((tag as { key?: unknown }).key ?? '').trim();
                if (key !== '') {
                    keys.push(key);
                }
            }
        }

        return [...new Set(keys)];
    }

    if (typeof tags === 'object') {
        return [...new Set(
            Object.keys(tags).filter((key) => typeof key === 'string' && key.trim() !== ''),
        )];
    }

    return [];
}
