<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStep;
use Illuminate\Validation\ValidationException;

class CustomWorkflowStepOrder
{
    /**
     * Partial-order gate ranks. Free steps share rank 3 and may appear in any order
     * relative to each other, but must follow any selected lower-rank gates.
     */
    private const GATE_RANKS = [
        'verify_licensing' => 0,
        'preprovision_group' => 1,
        'associate_site' => 2,
    ];

    /**
     * @param  list<mixed>  $rawSteps
     * @return list<ProvisioningStep>
     */
    public static function validate(array $rawSteps): array
    {
        if ($rawSteps === []) {
            throw ValidationException::withMessages([
                'steps' => 'Select at least one provisioning step.',
            ]);
        }

        $catalogue = array_map(fn (ProvisioningStep $step) => $step->value, ProvisioningStep::cases());
        $resolved = [];
        $seen = [];

        foreach ($rawSteps as $raw) {
            $value = (string) $raw;
            if (! in_array($value, $catalogue, true)) {
                throw ValidationException::withMessages([
                    'steps' => "Invalid step \"{$value}\".",
                ]);
            }
            if (isset($seen[$value])) {
                throw ValidationException::withMessages([
                    'steps' => "Duplicate step \"{$value}\".",
                ]);
            }
            $seen[$value] = true;
            $resolved[] = ProvisioningStep::from($value);
        }

        $previousRank = -1;
        $previousLabel = null;
        foreach ($resolved as $step) {
            $rank = self::rank($step);
            if ($rank < $previousRank) {
                $currentLabel = $step->label();
                throw ValidationException::withMessages([
                    'steps' => self::violationMessage($previousLabel ?? '', $currentLabel, $previousRank, $rank),
                ]);
            }
            $previousRank = $rank;
            $previousLabel = $step->label();
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $stepKeys
     */
    public static function isValid(array $stepKeys): bool
    {
        try {
            self::validate($stepKeys);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public static function rank(ProvisioningStep $step): int
    {
        return self::GATE_RANKS[$step->value] ?? 3;
    }

    private static function violationMessage(string $previousLabel, string $currentLabel, int $previousRank, int $currentRank): string
    {
        $gateOrder = 'licensing → preprovisioning → site/group → other steps';

        return "Invalid step order: \"{$currentLabel}\" cannot appear after \"{$previousLabel}\". Required partial order: {$gateOrder}.";
    }
}
