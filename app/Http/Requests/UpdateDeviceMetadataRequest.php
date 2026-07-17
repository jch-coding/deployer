<?php

namespace App\Http\Requests;

use App\Support\MacAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDeviceMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:17'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('mac_address')) {
                return;
            }

            $mac = $this->input('mac_address');
            if ($mac === null || $mac === '') {
                return;
            }

            if (! MacAddress::isValid((string) $mac)) {
                $validator->errors()->add(
                    'mac_address',
                    'mac_address must be a valid MAC address (e.g. aa:bb:cc:dd:ee:ff).'
                );
            }
        });
    }
}
