<?php

namespace App\Enums;

use App\Models\Device;

enum ProvisioningStep: string
{
    case VerifyLicensing = 'verify_licensing';
    case PreprovisionGroup = 'preprovision_group';
    case AssignDeviceFunction = 'assign_device_function';
    case WaitForOnline = 'wait_for_online';
    case AssociateSite = 'associate_site';
    case ResolveScopeId = 'resolve_scope_id';
    case NameDevice = 'name_device';
    case ConfigureVlanInterfaces = 'configure_vlan_interfaces';
    case CreateStackProfile = 'create_stack_profile';
    case WaitForVsfStackScope = 'wait_for_vsf_stack_scope';
    case ConfigureLagInterfaces = 'configure_lag_interfaces';
    case ConfigureEthernetInterfaces = 'configure_ethernet_interfaces';
    case ConfigureMirrorSessions = 'configure_mirror_sessions';
    case ClearLocalOverrides = 'clear_local_overrides';

    public function label(): string
    {
        return match ($this) {
            self::VerifyLicensing => 'Verify licensing',
            self::PreprovisionGroup => 'Preprovision to group',
            self::AssignDeviceFunction => 'Assign device function',
            self::WaitForOnline => 'Wait for device online',
            self::AssociateSite => 'Associate to site',
            self::ResolveScopeId => 'Resolve scope ID',
            self::NameDevice => 'Name device',
            self::ConfigureVlanInterfaces => 'Configure VLAN interfaces',
            self::CreateStackProfile => 'Create stack profile',
            self::WaitForVsfStackScope => 'Wait for VSF stack scope',
            self::ConfigureLagInterfaces => 'Configure LAG interfaces',
            self::ConfigureEthernetInterfaces => 'Configure ethernet interfaces',
            self::ConfigureMirrorSessions => 'Configure mirror sessions',
            self::ClearLocalOverrides => 'Clear local overrides',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::VerifyLicensing => 1,
            self::PreprovisionGroup => 2,
            self::AssignDeviceFunction => 3,
            self::WaitForOnline => 4,
            self::AssociateSite => 5,
            self::ResolveScopeId => 6,
            self::NameDevice => 7,
            self::ConfigureVlanInterfaces => 8,
            self::CreateStackProfile => 9,
            self::WaitForVsfStackScope => 10,
            self::ConfigureLagInterfaces => 11,
            self::ConfigureEthernetInterfaces => 12,
            self::ConfigureMirrorSessions => 13,
            self::ClearLocalOverrides => 14,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->order() <=> $b->order());

        return $cases;
    }

    public function shouldSkipForDevice(Device $device): bool
    {
        return match ($this) {
            self::WaitForVsfStackScope => ! $device->sku,
            self::CreateStackProfile => ! $device->sku && ! $device->vsx_profile,
            self::ConfigureMirrorSessions => ! self::deviceHasMirrorConfig($device),
            self::ConfigureVlanInterfaces => $device->interfaces()->where('interface_kind', 'VLAN')->doesntExist(),
            self::ConfigureLagInterfaces => $device->interfaces()->where('interface_kind', 'LAG')->doesntExist(),
            self::ConfigureEthernetInterfaces => $device->interfaces()->where('interface_kind', 'ETHERNET')->doesntExist(),
            self::ClearLocalOverrides => $device->sku === null,
            default => false,
        };
    }

    public static function deviceHasMirrorConfig(Device $device): bool
    {
        return $device->mirror_session_id !== null
            || ($device->mirror_dst_ports !== null && $device->mirror_dst_ports !== '')
            || ($device->mirror_name !== null && $device->mirror_name !== '');
    }

    public function isSwitchOnlineCheck(): bool
    {
        return $this === self::WaitForOnline;
    }

    public function isPollingStep(): bool
    {
        return in_array($this, [self::WaitForOnline, self::ResolveScopeId, self::WaitForVsfStackScope], true);
    }
}
