<?php

namespace App\Support;

class ClassicSiteTaskPayload
{
    /**
     * @param  array<string, mixed>  $siteDetail
     * @return array<string, mixed>
     */
    public static function buildClassicSiteBody(array $siteDetail): array
    {
        $address = is_array($siteDetail['site_address'] ?? null) ? $siteDetail['site_address'] : [];
        $body = [
            'site_name' => (string) ($siteDetail['site_name'] ?? ''),
            'site_address' => [
                'address' => (string) ($address['address'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['state'] ?? ''),
                'country' => (string) ($address['country'] ?? ''),
                'zipcode' => (string) ($address['zipcode'] ?? ''),
            ],
        ];

        $geo = is_array($siteDetail['geolocation'] ?? null) ? $siteDetail['geolocation'] : [];
        $latitude = trim((string) ($geo['latitude'] ?? ''));
        $longitude = trim((string) ($geo['longitude'] ?? ''));

        if ($latitude !== '' && $longitude !== '') {
            $body['geolocation'] = [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $centralSite
     * @return array<string, mixed>
     */
    public static function normalizeClassicSiteForForm(array $centralSite): array
    {
        $address = is_array($centralSite['site_address'] ?? null) ? $centralSite['site_address'] : [];
        $geo = is_array($centralSite['geolocation'] ?? null) ? $centralSite['geolocation'] : [];

        return [
            'site_id' => $centralSite['site_id'] ?? null,
            'site_name' => (string) ($centralSite['site_name'] ?? ''),
            'site_address' => [
                'address' => (string) ($address['address'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['state'] ?? ''),
                'country' => (string) ($address['country'] ?? ''),
                'zipcode' => (string) ($address['zipcode'] ?? ''),
            ],
            'geolocation' => [
                'latitude' => (string) ($geo['latitude'] ?? ''),
                'longitude' => (string) ($geo['longitude'] ?? ''),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $siteDetails
     */
    public static function deriveTaskStatusFromSiteDetails(array $siteDetails): ?string
    {
        if ($siteDetails === []) {
            return null;
        }

        $statuses = array_map(
            fn (array $site): string => (string) ($site['status'] ?? 'PENDING'),
            $siteDetails,
        );

        if (in_array('PENDING', $statuses, true)) {
            return null;
        }

        if (count(array_filter($statuses, fn (string $status): bool => $status === 'COMPLETED')) === count($statuses)) {
            return 'COMPLETED';
        }

        return 'FAILED';
    }
}
