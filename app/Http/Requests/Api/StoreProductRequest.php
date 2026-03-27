<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

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
            'name'                        => ['required', 'string', 'max:60'],
            'sku'                         => ['nullable', 'string', 'max:80'],
            'description'                 => ['nullable', 'string', 'max:120'],
            'price'                       => ['required', 'numeric', 'min:0'],
            'stock_quantity'              => ['nullable', 'integer', 'min:0'],
            'is_active'                   => ['sometimes', 'boolean'],
            'image'                       => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path'                  => ['nullable', 'string', 'max:2048'],
            'variations'                  => ['nullable', 'array', 'max:30'],
            'variations.*.name'           => ['required_with:variations', 'string', 'max:140'],
            'variations.*.sku'            => ['nullable', 'string', 'max:80'],
            'variations.*.price'          => ['required_with:variations', 'numeric', 'min:0'],
            'variations.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.attributes'     => ['nullable', 'array'],
            'variations.*.is_default'     => ['nullable', 'boolean'],
            'variations.*.is_active'      => ['nullable', 'boolean'],
        ];
    }
}
