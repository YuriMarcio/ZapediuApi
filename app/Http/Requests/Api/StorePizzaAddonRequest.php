<?php

namespace App\Http\Requests\Api;

use App\Models\OptionalFlow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePizzaAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(OptionalFlow::PIZZA_FEATURE_TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'image' => ['nullable', 'image', 'max:20480'],
            'is_active' => ['sometimes', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['single', 'per_size'])],
            'available_size_ids' => ['nullable', 'array'],
            'available_size_ids.*' => ['integer', 'exists:store_pizza_sizes,id'],
            // Preço único (bordas/ingredientes/molhos com pricing_mode=single)
            'price' => ['required_if:pricing_mode,single', 'nullable', 'numeric', 'min:0'],
            // Preço por tamanho (bordas com pricing_mode=per_size)
            'size_prices' => ['required_if:pricing_mode,per_size', 'nullable', 'array'],
            'size_prices.*' => ['numeric', 'min:0'],
            // Ingredientes: limite por pedido (metadado, ainda não aplicado no WhatsApp)
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
