import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { subscriptionTagKeys } from '@/lib/subscription-tags';

export type AvailableSubscription = {
    subscription_key: string;
    subscription_sku: string;
    license_type: string;
    available: number;
    quantity?: number;
    end_date: number | null;
    device_categories: string[];
    /** Tag keys from GreenLake (normalized to string[] on the server). */
    tags?: string[] | Record<string, string>;
};

export function subscriptionTags(subscription: AvailableSubscription): string[] {
    return subscriptionTagKeys(subscription.tags);
}

function formatTagsInline(tags: string[]): string {
    if (tags.length === 0) {
        return '';
    }

    return `[${tags.join(', ')}] `;
}

/** Plain-text label for native selects and screen readers. */
export function formatLicenseOptionLabel(subscription: AvailableSubscription): string {
    const sku = subscription.subscription_sku || '—';
    const seats = subscription.available;
    const tags = subscriptionTags(subscription);

    return `${formatTagsInline(tags)}${subscription.license_type} · ${sku} · ${seats} seat${seats === 1 ? '' : 's'} · ${subscription.subscription_key}`;
}

function licenseSummaryText(subscription: AvailableSubscription): string {
    const sku = subscription.subscription_sku || '—';
    const seats = subscription.available;

    return `${subscription.license_type} · ${sku} · ${seats} seat${seats === 1 ? '' : 's'} · ${subscription.subscription_key}`;
}

function SubscriptionTagBadges({ tags }: { tags: string[] }) {
    if (tags.length === 0) {
        return null;
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            {tags.map((tag) => (
                <Badge key={tag} variant="secondary" className="text-xs font-normal">
                    {tag}
                </Badge>
            ))}
        </span>
    );
}

export function LicenseOptionContent({ subscription }: { subscription: AvailableSubscription }) {
    const tags = subscriptionTags(subscription);

    return (
        <span className="flex min-w-0 flex-wrap items-center gap-2">
            <span className="min-w-0 text-left">{licenseSummaryText(subscription)}</span>
            <SubscriptionTagBadges tags={tags} />
        </span>
    );
}

export function filterSubscriptionsByDeviceCategory(
    subscriptions: AvailableSubscription[],
    switchesOnly: boolean,
    apsOnly: boolean,
): AvailableSubscription[] {
    if (!switchesOnly && !apsOnly) {
        return subscriptions;
    }

    return subscriptions.filter((subscription) => {
        const categories = subscription.device_categories ?? [];
        if (switchesOnly) {
            return categories.includes('switch');
        }
        if (apsOnly) {
            return categories.includes('ap');
        }

        return true;
    });
}

type LicenseSelectProps = {
    id?: string;
    value: string;
    subscriptions: AvailableSubscription[];
    onChange: (subscriptionKey: string) => void;
    placeholder?: string;
    className?: string;
    disabled?: boolean;
};

const defaultTriggerClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export default function LicenseSelect({
    id,
    value,
    subscriptions,
    onChange,
    placeholder = 'Select a license',
    className = defaultTriggerClassName,
    disabled = false,
}: LicenseSelectProps) {
    const selectedSubscription = subscriptions.find((s) => s.subscription_key === value);
    const isDisabled = disabled || subscriptions.length === 0;
    const emptyLabel = subscriptions.length === 0 ? 'No licenses available' : placeholder;

    return (
        <Select
            key={subscriptions.map((s) => s.subscription_key).join('|') || 'empty'}
            value={value === '' ? undefined : value}
            onValueChange={onChange}
            disabled={isDisabled}
        >
            <SelectTrigger id={id} className={className} data-test="license-select-trigger">
                <SelectValue placeholder={emptyLabel}>
                    {selectedSubscription ? (
                        <LicenseOptionContent subscription={selectedSubscription} />
                    ) : null}
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                {subscriptions.map((subscription) => (
                    <SelectItem
                        key={subscription.subscription_key}
                        value={subscription.subscription_key}
                        textValue={formatLicenseOptionLabel(subscription)}
                    >
                        <LicenseOptionContent subscription={subscription} />
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
