<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
