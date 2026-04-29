<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceInterfacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'updates' => ['nullable', 'array'],
            'updates.*.id' => ['required', 'integer', 'distinct'],
            'updates.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'updates.*.ip_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'updates.*.enable' => ['sometimes', 'boolean'],
            'updates.*.jumbo_frames' => ['sometimes', 'boolean'],
            'updates.*.routing' => ['sometimes', 'boolean'],
            'updates.*.shutdown_on_split' => ['sometimes', 'boolean'],
            'updates.*.vrf_forwarding' => ['sometimes', 'string', 'max:255'],
            'updates.*.sw_profile' => ['sometimes', 'nullable', 'string', 'max:255'],
            'updates.*.portchannel_lag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'updates.*.interface_mode' => ['sometimes', Rule::in(['ACCESS', 'TRUNK'])],
            'updates.*.access_vlan' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4094'],
            'updates.*.native_vlan' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4094'],
            'updates.*.trunk_vlan_all' => ['sometimes', 'boolean'],
            'updates.*.trunk_vlan_ranges' => ['sometimes', 'nullable', 'string', 'max:255'],
            'updates.*.lacp_mode' => ['sometimes', Rule::in(['ACTIVE', 'PASSIVE', 'AUTO'])],
            'updates.*.lacp_rate' => ['sometimes', Rule::in(['FAST', 'SLOW'])],
            'updates.*.trunk_type' => ['sometimes', Rule::in(['LACP', 'TRUNK', 'DT_TRUNK', 'MULTI_CHASSIS', 'MULTI_CHASSIS_STATIC'])],
            'updates.*.lacp_port_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'updates.*.lacp_port_list' => ['sometimes', 'nullable', 'array'],
            'updates.*.lacp_port_list.*' => ['string', 'max:255'],
            'updates.*.admin_edge_port' => ['sometimes', 'boolean'],
            'updates.*.admin_edge_port_trunk' => ['sometimes', 'boolean'],
            'updates.*.bpdu_guard' => ['sometimes', 'boolean'],
            'updates.*.loop_guard' => ['sometimes', 'boolean'],
        ];
    }
}
