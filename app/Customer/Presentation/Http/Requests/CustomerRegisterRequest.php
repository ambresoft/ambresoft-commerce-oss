<?php

namespace App\Customer\Presentation\Http\Requests;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Identity\Presentation\Http\Requests\EmailRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CustomerRegisterRequest extends EmailRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => [...parent::rules()['email'], Rule::unique('users', 'email')],
            ...CustomerAccounts::nameRules(),
            'password' => ['required', 'string', 'confirmed', Password::min(PasswordPolicy::CUSTOMER_MIN)->max(PasswordPolicy::MAX)],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => __('auth.register_failed')];
    }

    public function attributes(): array
    {
        return ['first_name' => __('customers.first_name'), 'last_name' => __('customers.last_name')];
    }
}
