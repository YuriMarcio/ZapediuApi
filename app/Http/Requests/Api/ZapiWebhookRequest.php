<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ZapiWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'string', 'max:160'],
            'messageId' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'text.message' => ['nullable', 'string', 'max:5000'],
            'buttonReply.id' => ['nullable', 'string', 'max:190'],
            'buttonId' => ['nullable', 'string', 'max:190'],
            'instanceId' => ['nullable', 'string', 'max:190'],
            'order' => ['nullable', 'array'],
        ];
    }
}
