import { Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type CnacStaticTagsPickerProps = {
    value: string[];
    onChange: (tags: string[]) => void;
    availableTags?: string[];
    disabled?: boolean;
    className?: string;
};

function normalizeTag(value: string): string {
    return value.trim();
}

export default function CnacStaticTagsPicker({
    value,
    onChange,
    availableTags = [],
    disabled = false,
    className,
}: CnacStaticTagsPickerProps) {
    const [draft, setDraft] = useState('');
    const [selectKey, setSelectKey] = useState(0);

    const selected = useMemo(
        () =>
            value
                .map((tag) => normalizeTag(tag))
                .filter((tag, index, all) => tag !== '' && all.indexOf(tag) === index),
        [value],
    );

    const unusedAvailable = useMemo(
        () =>
            availableTags
                .map((tag) => normalizeTag(tag))
                .filter(
                    (tag) =>
                        tag !== '' &&
                        !selected.some(
                            (item) => item.toLowerCase() === tag.toLowerCase(),
                        ),
                ),
        [availableTags, selected],
    );

    const addTag = (raw: string) => {
        const tag = normalizeTag(raw);
        if (tag === '' || disabled) {
            return;
        }
        if (selected.some((item) => item.toLowerCase() === tag.toLowerCase())) {
            return;
        }
        onChange([...selected, tag]);
    };

    const removeTag = (tag: string) => {
        if (disabled) {
            return;
        }
        onChange(selected.filter((item) => item !== tag));
    };

    return (
        <div className={className} data-test="cnac-static-tags-picker">
            <div className="flex flex-wrap gap-2">
                <Select
                    key={selectKey}
                    disabled={disabled || unusedAvailable.length === 0}
                    onValueChange={(next) => {
                        addTag(next);
                        setSelectKey((prev) => prev + 1);
                    }}
                >
                    <SelectTrigger
                        className="w-[14rem]"
                        data-test="cnac-static-tags-select"
                    >
                        <SelectValue placeholder="Select existing tag" />
                    </SelectTrigger>
                    <SelectContent>
                        {unusedAvailable.map((tag) => (
                            <SelectItem key={tag} value={tag}>
                                {tag}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <div className="flex min-w-[14rem] flex-1 gap-2">
                    <Input
                        value={draft}
                        disabled={disabled}
                        placeholder="Or type a new tag"
                        data-test="cnac-static-tags-input"
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                addTag(draft);
                                setDraft('');
                            }
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        disabled={disabled || normalizeTag(draft) === ''}
                        aria-label="Add static tag"
                        data-test="cnac-static-tags-add"
                        onClick={() => {
                            addTag(draft);
                            setDraft('');
                        }}
                    >
                        <Plus className="size-4" aria-hidden />
                    </Button>
                </div>
            </div>

            {selected.length > 0 ? (
                <div
                    className="mt-2 flex flex-wrap gap-1.5"
                    data-test="cnac-static-tags-selected"
                >
                    {selected.map((tag) => (
                        <Badge
                            key={tag}
                            variant="secondary"
                            className="gap-1 pr-1"
                        >
                            {tag}
                            <button
                                type="button"
                                disabled={disabled}
                                className="rounded-sm p-0.5 hover:bg-muted"
                                aria-label={`Remove tag ${tag}`}
                                data-test="cnac-static-tags-remove"
                                onClick={() => removeTag(tag)}
                            >
                                <X className="size-3" aria-hidden />
                            </button>
                        </Badge>
                    ))}
                </div>
            ) : (
                <p className="mt-2 text-xs text-muted-foreground">
                    No static tags selected (optional).
                </p>
            )}
        </div>
    );
}
