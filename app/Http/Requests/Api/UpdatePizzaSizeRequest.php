<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePizzaSizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:60'],
            'slice_count' => ['nullable', 'integer', 'min:1', 'max:255'],
            'max_flavors' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
