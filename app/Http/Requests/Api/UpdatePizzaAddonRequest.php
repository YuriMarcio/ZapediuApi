<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePizzaAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'image' => ['nullable', 'image', 'max:20480'],
            'is_active' => ['sometimes', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['single', 'per_size'])],
            'available_size_ids' => ['nullable', 'array'],
            'available_size_ids.*' => ['integer', 'exists:store_pizza_sizes,id'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'size_prices' => ['sometimes', 'nullable', 'array'],
            'size_prices.*' => ['numeric', 'min:0'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
