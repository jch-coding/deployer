import type { AvailableSubscription } from '@/components/licensing/LicenseSelect';
import { subscriptionTagKeys } from '@/lib/subscription-tags';
import {
    type LicenseTypeOption,
    licenseTypeMatchesTierDescription,
} from '@/lib/license-types';

export function matchingSubscriptionsForPool(
    subscriptions: AvailableSubscription[],
    tag: string,
    licenseType: LicenseTypeOption,
): AvailableSubscription[] {
    const trimmedTag = tag.trim();
    if (trimmedTag === '') {
        return [];
    }

    return subscriptions.filter((subscription) => {
        const tagKeys = subscriptionTagKeys(subscription.tags);
        if (!tagKeys.includes(trimmedTag)) {
            return false;
        }

        return licenseTypeMatchesTierDescription(licenseType, subscription.license_type);
    });
}

export function poolAvailableSeats(
    subscriptions: AvailableSubscription[],
    tag: string,
    licenseType: LicenseTypeOption,
): number {
    return matchingSubscriptionsForPool(subscriptions, tag, licenseType).reduce(
        (total, subscription) => total + subscription.available,
        0,
    );
}

export function collectLicenseTags(subscriptions: AvailableSubscription[]): string[] {
    const tags = new Set<string>();

    for (const subscription of subscriptions) {
        for (const tag of subscriptionTagKeys(subscription.tags)) {
            tags.add(tag);
        }
    }

    return [...tags].sort((a, b) => a.localeCompare(b));
}
