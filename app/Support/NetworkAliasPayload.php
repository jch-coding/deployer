<?php

namespace App\Support;

class NetworkAliasPayload
{
    public const ALIAS_NAME = 'BVSD-VIVI-SUBNET';

    public const ALIAS_TYPE = 'ALIAS_NETWORK';

    public const SITE_NAME_PATTERN = '/^(\d{2})\s*-\s*.+/';

    /**
     * Extract the leading site number from names like "07 - Boulder".
     */
    public static function extractSiteNumber(string $siteName): ?int
    {
        if (! preg_match(self::SITE_NAME_PATTERN, trim($siteName), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Build the default CIDR 10.{n}.9.0/24 from a site name, or null if unparseable.
     */
    public static function defaultCidrFromSiteName(string $siteName): ?string
    {
        $siteNumber = self::extractSiteNumber($siteName);

        if ($siteNumber === null) {
            return null;
        }

        return '10.'.$siteNumber.'.9.0/24';
    }

    /**
     * Resolve the network CIDR from an optional override or the site name preset.
     */
    public static function resolveNetworkIpv4Address(string $siteName, ?string $override = null): ?string
    {
        $trimmedOverride = trim((string) $override);

        if ($trimmedOverride !== '') {
            return $trimmedOverride;
        }

        return self::defaultCidrFromSiteName($siteName);
    }

    /**
     * @return array{
     *     default-value: array{network-address-value: array{network-ipv4-address: string}},
     *     name: string,
     *     type: string
     * }
     */
    public static function buildBody(string $networkIpv4Address): array
    {
        return [
            'default-value' => [
                'network-address-value' => [
                    'network-ipv4-address' => $networkIpv4Address,
                ],
            ],
            'name' => self::ALIAS_NAME,
            'type' => self::ALIAS_TYPE,
        ];
    }
}
