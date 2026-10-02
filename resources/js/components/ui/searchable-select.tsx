import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import {
    filterSearchableSelectOptions,
    type SearchableSelectOption,
} from '@/lib/searchable-select';
import { cn } from '@/lib/utils';

export type { SearchableSelectOption };

type SearchableSelectProps = {
    value: string | undefined;
    onValueChange: (value: string) => void;
    options: SearchableSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
    'data-test'?: string;
    renderOptionLabel?: (option: SearchableSelectOption) => ReactNode;
};

export function SearchableSelect({
    value,
    onValueChange,
    options,
    placeholder = 'Select…',
    disabled = false,
    className,
    'aria-label': ariaLabel,
    'data-test': dataTest,
    renderOptionLabel,
}: SearchableSelectProps) {
    const [query, setQuery] = useState('');

    const selected = useMemo(
        () => options.find((option) => option.value === value) ?? null,
        [options, value],
    );

    const filtered = useMemo(
        () => filterSearchableSelectOptions(options, query),
        [options, query],
    );

    return (
        <Combobox
            value={selected}
            onChange={(option: SearchableSelectOption | null) => {
                if (option) {
                    onValueChange(option.value);
                }
            }}
            onClose={() => setQuery('')}
            disabled={disabled}
            immediate
        >
            <div className="relative">
                <ComboboxInput
                    aria-label={ariaLabel}
                    data-test={dataTest}
                    displayValue={(option: SearchableSelectOption | null) =>
                        option?.label ?? ''
                    }
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={placeholder}
                    className={cn(
                        'border-input placeholder:text-muted-foreground flex h-9 w-full rounded-md border bg-transparent py-1 pr-8 pl-3 text-sm shadow-xs outline-none',
                        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                        'disabled:cursor-not-allowed disabled:opacity-50',
                        className,
                    )}
                />
                <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-2">
                    <ChevronDownIcon
                        className="size-4 opacity-50"
                        aria-hidden
                    />
                </ComboboxButton>

                <ComboboxOptions
                    anchor="bottom start"
                    className={cn(
                        'border-border bg-popover text-popover-foreground z-50 max-h-60 min-w-[var(--input-width)] overflow-auto rounded-md border p-1 shadow-md',
                        '[--anchor-gap:4px]',
                    )}
                >
                    {filtered.length === 0 ? (
                        <div className="text-muted-foreground px-2 py-1.5 text-sm">
                            No matches
                        </div>
                    ) : (
                        filtered.map((option) => (
                            <ComboboxOption
                                key={option.value}
                                value={option}
                                className={cn(
                                    'group flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none',
                                    'data-focus:bg-accent data-focus:text-accent-foreground',
                                )}
                            >
                                <CheckIcon className="size-4 opacity-0 group-data-selected:opacity-100" />
                                <span className="min-w-0 flex-1 truncate">
                                    {renderOptionLabel
                                        ? renderOptionLabel(option)
                                        : option.label}
                                </span>
                            </ComboboxOption>
                        ))
                    )}
                </ComboboxOptions>
            </div>
        </Combobox>
    );
}
