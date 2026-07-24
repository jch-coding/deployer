<?php

use App\Enums\ProvisioningStep;
use App\Services\Provisioning\CustomWorkflowStepOrder;
use Illuminate\Validation\ValidationException;

it('accepts a valid gate-then-free custom order', function () {
    $steps = CustomWorkflowStepOrder::validate([
        ProvisioningStep::VerifyLicensing->value,
        ProvisioningStep::PreprovisionGroup->value,
        ProvisioningStep::AssociateSite->value,
        ProvisioningStep::ConfigureEthernetInterfaces->value,
        ProvisioningStep::ConfigureVlanInterfaces->value,
    ]);

    expect($steps)->toHaveCount(5)
        ->and($steps[3])->toBe(ProvisioningStep::ConfigureEthernetInterfaces)
        ->and($steps[4])->toBe(ProvisioningStep::ConfigureVlanInterfaces);
});

it('allows free reorder among post-gate steps', function () {
    $steps = CustomWorkflowStepOrder::validate([
        ProvisioningStep::NameDevice->value,
        ProvisioningStep::WaitForOnline->value,
        ProvisioningStep::ConfigureLagInterfaces->value,
    ]);

    expect(array_map(fn ($step) => $step->value, $steps))->toBe([
        ProvisioningStep::NameDevice->value,
        ProvisioningStep::WaitForOnline->value,
        ProvisioningStep::ConfigureLagInterfaces->value,
    ]);
});

it('rejects site before licensing', function () {
    expect(fn () => CustomWorkflowStepOrder::validate([
        ProvisioningStep::AssociateSite->value,
        ProvisioningStep::VerifyLicensing->value,
    ]))->toThrow(ValidationException::class);
});

it('rejects free step before preprovision when both are selected', function () {
    expect(fn () => CustomWorkflowStepOrder::validate([
        ProvisioningStep::ConfigureVlanInterfaces->value,
        ProvisioningStep::PreprovisionGroup->value,
    ]))->toThrow(ValidationException::class);
});

it('rejects empty and duplicate steps', function () {
    expect(fn () => CustomWorkflowStepOrder::validate([]))
        ->toThrow(ValidationException::class);

    expect(fn () => CustomWorkflowStepOrder::validate([
        ProvisioningStep::AssociateSite->value,
        ProvisioningStep::AssociateSite->value,
    ]))->toThrow(ValidationException::class);
});

it('rejects unknown step keys', function () {
    expect(fn () => CustomWorkflowStepOrder::validate(['not_a_real_step']))
        ->toThrow(ValidationException::class);
});

it('allows licensing then free step when intermediate gates are omitted', function () {
    $steps = CustomWorkflowStepOrder::validate([
        ProvisioningStep::VerifyLicensing->value,
        ProvisioningStep::NameDevice->value,
    ]);

    expect($steps)->toHaveCount(2);
});
