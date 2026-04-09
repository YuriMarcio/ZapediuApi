<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_slug'             => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)],
            'seller_code'           => ['required', 'string', Rule::exists('users', 'seller_code')->where('role', 'seller')],

            'owner'                 => ['required', 'array'],
            'owner.name'            => ['required', 'string', 'max:255'],
            'owner.email'           => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner.password'        => ['required', 'string', 'min:8', 'confirmed'],
            'owner.phone'           => ['required', 'string', 'max:30'],
            'owner.cpf'             => ['required', 'string', 'max:20'],

            'company'               => ['required', 'array'],
            'company.trade_name'    => ['required', 'string', 'max:255'],
            'company.legal_name'    => ['nullable', 'string', 'max:255'],
            'company.document'      => ['nullable', 'string', 'max:20'],
            'company.phone'         => ['nullable', 'string', 'max:30'],
            'company.whatsapp'      => ['required', 'string', 'max:30'],

            'store'                 => ['required', 'array'],
            'store.name'            => ['required', 'string', 'max:255'],
            'store.slug'            => ['nullable', 'string', 'max:255', Rule::unique('stores', 'slug')],
            'store.timezone'        => ['nullable', 'string', 'max:50'],

            'address'               => ['required', 'array'],
            'address.zipcode'       => ['required', 'string', 'max:20'],
            'address.street'        => ['required', 'string', 'max:255'],
            'address.number'        => ['required', 'string', 'max:40'],
            'address.district'      => ['required', 'string', 'max:255'],
            'address.complement'    => ['nullable', 'string', 'max:255'],
            'address.city'          => ['required', 'string', 'max:255'],
            'address.state'         => ['required', 'string', 'size:2'],

            'business_hours'            => ['required', 'array', 'min:1', 'max:7'],
            'business_hours.*.day'      => ['required', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'business_hours.*.enabled'  => ['required', 'boolean'],
            'business_hours.*.open'     => ['nullable', 'string', 'date_format:H:i'],
            'business_hours.*.close'    => ['nullable', 'string', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'seller_code.exists'            => 'Código do vendedor inválido.',
            'plan_slug.required'            => 'Plano é obrigatório.',
            'plan_slug.exists'              => 'Plano inválido.',
            'owner.email.unique'            => 'Este email já está em uso.',
            'owner.password.required'       => 'Senha é obrigatória.',
            'owner.password.min'            => 'A senha deve ter no mínimo 8 caracteres.',
            'owner.password.confirmed'      => 'A confirmação de senha não confere.',
            'owner.cpf.required'            => 'CPF é obrigatório.',
            'company.document.max'          => 'CNPJ inválido.',
            'store.slug.unique'             => 'Este identificador já está em uso.',
            'address.zipcode.required'      => 'CEP é obrigatório.',
            'address.state.size'            => 'Estado deve ter 2 letras.',
            'business_hours.*.day.in'       => 'Dia inválido. Use: monday, tuesday, wednesday, thursday, friday, saturday ou sunday.',
            'business_hours.*.open.date_format' => 'Horário de abertura inválido.',
            'business_hours.*.close.date_format' => 'Horário de fechamento inválido.',
        ];
    }
}
