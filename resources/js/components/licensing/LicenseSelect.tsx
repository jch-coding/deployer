export type AvailableSubscription = {
    subscription_key: string;
    subscription_sku: string;
    license_type: string;
    available: number;
    end_date: number | null;
    device_categories: string[];
};

export function formatLicenseOptionLabel(subscription: AvailableSubscription): string {
    const sku = subscription.subscription_sku || '—';
    const seats = subscription.available;

    return `${subscription.license_type} · ${sku} · ${seats} seat${seats === 1 ? '' : 's'} · ${subscription.subscription_key}`;
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

const defaultClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export default function LicenseSelect({
    id,
    value,
    subscriptions,
    onChange,
    placeholder = 'Select a license',
    className = defaultClassName,
    disabled = false,
}: LicenseSelectProps) {
    return (
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={className}
            disabled={disabled || subscriptions.length === 0}
        >
            <option value="">{subscriptions.length === 0 ? 'No licenses available' : placeholder}</option>
            {subscriptions.map((subscription) => (
                <option key={subscription.subscription_key} value={subscription.subscription_key}>
                    {formatLicenseOptionLabel(subscription)}
                </option>
            ))}
        </select>
    );
}
