<?php

namespace App\Http\Requests\Api;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePizzaConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'flavor_price_rule' => ['sometimes', Rule::in(Store::PIZZA_FLAVOR_PRICE_RULES)],
            'features' => ['sometimes', 'array'],
            'features.bordas' => ['sometimes', 'boolean'],
            'features.ingredientes' => ['sometimes', 'boolean'],
            'features.molhos' => ['sometimes', 'boolean'],
            'features.observacoes' => ['sometimes', 'boolean'],
        ];
    }
}
