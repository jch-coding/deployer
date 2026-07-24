/**
 * Client-side mirror of CustomWorkflowStepOrder partial-order ranks.
 * Gate steps must appear in non-decreasing rank order; free steps share rank 3.
 */
const GATE_RANKS: Record<string, number> = {
    verify_licensing: 0,
    preprovision_group: 1,
    associate_site: 2,
};

export function customWorkflowStepRank(stepKey: string): number {
    return GATE_RANKS[stepKey] ?? 3;
}

export function validateCustomWorkflowStepOrder(
    stepKeys: string[],
    labelsByKey: Record<string, string> = {},
): string | null {
    if (stepKeys.length === 0) {
        return 'Select at least one provisioning step.';
    }

    const seen = new Set<string>();
    let previousRank = -1;
    let previousLabel: string | null = null;

    for (const key of stepKeys) {
        if (seen.has(key)) {
            return `Duplicate step "${labelsByKey[key] ?? key}".`;
        }
        seen.add(key);

        const rank = customWorkflowStepRank(key);
        const label = labelsByKey[key] ?? key;
        if (rank < previousRank) {
            return `Invalid step order: "${label}" cannot appear after "${previousLabel}". Required partial order: licensing → preprovisioning → site/group → other steps.`;
        }
        previousRank = rank;
        previousLabel = label;
    }

    return null;
}
