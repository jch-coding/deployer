export type SearchableSelectOption = {
    value: string;
    label: string;
};

/**
 * Case-insensitive substring filter for searchable select options.
 * Empty query returns all options.
 */
export function filterSearchableSelectOptions<T extends SearchableSelectOption>(
    options: T[],
    query: string,
): T[] {
    const trimmed = query.trim().toLowerCase();
    if (trimmed === '') {
        return options;
    }

    return options.filter((option) =>
        option.label.toLowerCase().includes(trimmed),
    );
}
