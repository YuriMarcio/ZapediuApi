<?php

namespace App\Http\Requests\Api;

use App\Models\Category;
use Illuminate\Support\Str;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $companyId = $this->user()?->company_id;
                    $slug = Str::slug((string) $value);
                    $categoryId = $this->route('category')?->id;

                    $exists = Category::query()
                        ->where('company_id', $companyId)
                        ->where('slug', $slug)
                        ->when($categoryId !== null, fn ($query) => $query->whereKeyNot($categoryId))
                        ->exists();

                    if ($exists) {
                        $fail('Categoria duplicada.');
                    }
                },
            ],
            'icon' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}