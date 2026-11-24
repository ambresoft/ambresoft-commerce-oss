<?php

namespace App\Identity\Presentation\Http\Requests;

use App\Identity\Application\Command\RegisterUserData;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends EmailRequest
{
    public function toData(): RegisterUserData
    {
        return new RegisterUserData(
            name: $this->validated('name'),
            email: $this->validated('email'),
            password: $this->validated('password'),
        );
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => [...parent::rules()['email'], Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'Unable to register with these details. Try signing in or resetting your password.'];
    }
}
