<?php

namespace App\Identity\Presentation\Http\Requests;

use App\Identity\Application\Command\ResetPasswordData;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Identity\Presentation\Http\AuthArea;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends EmailRequest
{
    public function toData(): ResetPasswordData
    {
        return new ResetPasswordData(
            email: $this->validated('email'),
            token: $this->validated('token'),
            password: $this->validated('password'),
        );
    }

    public function rules(): array
    {
        return [...parent::rules(), 'token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'confirmed', $this->passwordRule()]];
    }

    /** Staff pages keep the staff minimum; the customer page allows customer passwords (a staff identity resetting there loses administration entry until it uses 15+). */
    private function passwordRule(): Password
    {
        return AuthArea::admin($this) ? Password::defaults() : Password::min(PasswordPolicy::CUSTOMER_MIN)->max(PasswordPolicy::MAX);
    }
}
