<?php

namespace App\Http\Requests\Api;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $companyId = $this->user()?->company_id;
                    $slug = Str::slug((string) $value);

                    $exists = Category::query()
                        ->where('company_id', $companyId)
                        ->where('slug', $slug)
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

    public function messages(): array
    {
        return [
            'name.required' => 'O nome da categoria e obrigatorio.',
            'name.max' => 'O nome da categoria deve ter no maximo 80 caracteres.',
            'color.regex' => 'A cor deve estar em hexadecimal valido.',
            'sort_order.integer' => 'A ordem de exibicao deve ser um numero inteiro.',
            'sort_order.min' => 'A ordem de exibicao nao pode ser negativa.',
            'image.image' => 'O arquivo enviado deve ser uma imagem valida.',
            'image.mimes' => 'A imagem deve ser do tipo jpg, jpeg, png ou webp.',
            'image.max' => 'A imagem deve ter no maximo 4 MB.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $duplicateName = in_array('Categoria duplicada.', $errors['name'] ?? [], true);

        throw new HttpResponseException(response()->json([
            'message' => $duplicateName
                ? 'Ja existe uma categoria com esse nome nesta loja.'
                : 'Os dados informados para a categoria sao invalidos.',
            'errors' => $errors,
        ], $duplicateName ? 409 : 422));
    }
}