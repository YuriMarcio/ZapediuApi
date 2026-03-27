<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_phone' => ['required', 'string', 'max:30'],
            'segment' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}