<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'store_id'                    => ['required', 'integer', 'exists:stores,id'],
            'category_id'                 => ['nullable', 'integer', 'exists:categories,id'],
            'selection_group_id'          => ['nullable', 'integer', 'exists:selection_groups,id'],
            'optional_flow_id'            => ['nullable', 'integer', 'exists:optional_flows,id'],
            'variation_group_id'          => [
                'nullable',
                'integer',
                Rule::exists('variation_groups', 'id')->where(function ($query): void {
                    $user = $this->user();

                    if ($user?->company_id !== null) {
                        $query->where('company_id', $user->company_id);
                    }
                }),
            ],
            'name'                        => ['required', 'string', 'max:100'],
            'sku'                         => ['nullable', 'string', 'max:80'],
            'description'                 => ['nullable', 'string', 'max:300'],
            'price'                       => ['required', 'numeric', 'min:0'],
            'promotional_price'           => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'stock_quantity'              => ['nullable', 'integer', 'min:0'],
            'track_stock'                 => ['sometimes', 'boolean'],
            'is_active'                   => ['sometimes', 'boolean'],
            'available_for_combo'         => ['nullable', 'boolean'],
            'is_combo'                    => ['sometimes', 'boolean'],
            'combo_units'                 => ['nullable', 'array', 'max:10'],
            'combo_units.*.product_id'    => ['required_with:combo_units', 'integer', 'exists:products,id'],
            'combo_units.*.variation_id'  => ['nullable', 'integer', 'exists:product_variations,id'],
            'combo_units.*.label'         => ['nullable', 'string', 'max:80'],
            'image'                       => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path'                  => ['nullable', 'string', 'max:2048'],
            'variations'                  => ['nullable', 'array', 'max:30'],
            'variations.*.name'           => ['required_with:variations', 'string', 'max:140'],
            'variations.*.sku'            => ['nullable', 'string', 'max:80'],
            'variations.*.price'          => ['required_with:variations', 'numeric', 'min:0'],
            'variations.*.additional_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.attributes'     => ['nullable', 'array'],
            'variations.*.is_default'     => ['nullable', 'boolean'],
            'variations.*.is_active'      => ['nullable', 'boolean'],
            'variations.*.store_pizza_size_id' => ['nullable', 'integer', 'exists:store_pizza_sizes,id'],
            'variations.*.price_mode'     => ['nullable', Rule::in(['inherit', 'override'])],
            'variations.*.override_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
