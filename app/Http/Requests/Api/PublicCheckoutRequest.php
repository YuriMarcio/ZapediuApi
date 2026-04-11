<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['sometimes', 'string', 'max:255'],
            'delivery_mode' => ['sometimes', 'string', Rule::in(['store', 'platform'])],
            'success_url' => ['nullable', 'url:http,https', 'max:500'],
            'failure_url' => ['nullable', 'url:http,https', 'max:500'],
            'pending_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }
}