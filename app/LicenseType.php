<?php

namespace App;

enum LicenseType: string
{
    case FoundationAP = 'Foundation AP';
    case AdvancedAP = 'Advanced AP';
    case FoundationGateway = 'Foundation Gateway';
    case FoundationSwitchClass1 = 'Foundation-Switch-Class-1';
    case AdvancedSwitchClass1 = 'Advanced-Switch-Class-1';
    case FoundationSwitchClass2 = 'Foundation-Switch-Class-2';
    case AdvancedSwitchClass2 = 'Advanced-Switch-Class-2';
    case FoundationSwitchClass3 = 'Foundation-Switch-Class-3';
    case AdvancedSwitchClass3 = 'Advanced-Switch-Class-3';
    case FoundationSwitchClass4 = 'Foundation-Switch-Class-4';
    case AdvancedSwitchClass4 = 'Advanced-Switch-Class-4';
    case FoundationSwitchClass5 = 'Foundation-Switch-Class-5';
    case AdvancedSwitchClass5 = 'Advanced-Switch-Class-5';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public static function tryFromValue(string $value): ?self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $direct = self::tryFrom($trimmed);
        if ($direct !== null) {
            return $direct;
        }

        return self::fromTierDescription($trimmed);
    }

    public static function fromTierDescription(string $tierDescription): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->matchesTierDescription($tierDescription)) {
                return $case;
            }
        }

        return null;
    }

    public function matchesTierDescription(string $tierDescription): bool
    {
        if (strcasecmp(trim($tierDescription), $this->value) === 0) {
            return true;
        }

        $normalized = self::normalizeTierDescription($tierDescription);

        return match ($this) {
            self::FoundationAP => self::containsAll($normalized, ['foundation', 'ap'])
                && ! self::containsAny($normalized, ['switch', 'gateway']),
            self::AdvancedAP => self::containsAll($normalized, ['advanced', 'ap'])
                && ! self::containsAny($normalized, ['switch', 'gateway']),
            self::FoundationGateway => self::containsAll($normalized, ['foundation', 'gateway']),
            self::FoundationSwitchClass1 => self::isSwitchTier($normalized, 'foundation', 1),
            self::AdvancedSwitchClass1 => self::isSwitchTier($normalized, 'advanced', 1),
            self::FoundationSwitchClass2 => self::isSwitchTier($normalized, 'foundation', 2),
            self::AdvancedSwitchClass2 => self::isSwitchTier($normalized, 'advanced', 2),
            self::FoundationSwitchClass3 => self::isSwitchTier($normalized, 'foundation', 3),
            self::AdvancedSwitchClass3 => self::isSwitchTier($normalized, 'advanced', 3),
            self::FoundationSwitchClass4 => self::isSwitchTier($normalized, 'foundation', 4),
            self::AdvancedSwitchClass4 => self::isSwitchTier($normalized, 'advanced', 4),
            self::FoundationSwitchClass5 => self::isSwitchTier($normalized, 'foundation', 5),
            self::AdvancedSwitchClass5 => self::isSwitchTier($normalized, 'advanced', 5),
        };
    }

    /**
     * @return array<int, string>
     */
    public function deviceCategories(): array
    {
        return match ($this) {
            self::FoundationAP, self::AdvancedAP => ['ap'],
            self::FoundationGateway => ['gateway'],
            default => ['switch'],
        };
    }

    private static function normalizeTierDescription(string $tierDescription): string
    {
        $normalized = strtolower(trim($tierDescription));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isSwitchTier(string $normalized, string $tier, int $class): bool
    {
        if (! str_contains($normalized, 'switch') && ! str_contains($normalized, 'class')) {
            return false;
        }

        $hasTier = str_contains($normalized, $tier);
        $classPatterns = [
            "class {$class}",
            "class{$class}",
            "class {$class}",
        ];

        $hasClass = false;
        foreach ($classPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                $hasClass = true;
                break;
            }
        }

        if (! $hasClass && preg_match('/class[\s-]*'.$class.'(?!\d)/', $normalized) === 1) {
            $hasClass = true;
        }

        if ($class === 1 && ! $hasClass && $hasTier && str_contains($normalized, 'switch')) {
            // Legacy short descriptions like "Advanced Switch" map to Class-1 when no class is specified.
            return ! preg_match('/class[\s-]*[2-5]/', $normalized);
        }

        return $hasTier && $hasClass;
    }
}
