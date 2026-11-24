<?php

namespace App\Admin\Requests;

use App\Authorization\Application\Command\SaveStaffData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->route('user') === null ? 'users.create' : 'users.update')
            && (! $this->has('role_ids') || Gate::allows('users.assign-roles'))
            && (! $this->filled('password') || Gate::allows('users.set-password'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'invite' => ['sometimes', 'boolean'],
            'password' => [$this->route('user') === null && ! $this->boolean('invite') ? 'required' : 'nullable', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['required', 'boolean'],
            'role_ids' => [$this->boolean('invite') ? 'required' : 'sometimes', 'array', $this->boolean('invite') ? 'min:1' : 'min:0', 'max:50'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')],
        ];
    }

    public function toData(): SaveStaffData
    {
        return new SaveStaffData($this->validated('name'), $this->validated('email'), $this->route('user') === null && $this->boolean('invite') ? null : ($this->validated('password') ?: null),
            $this->boolean('is_active'), $this->has('role_ids') ? array_map(strval(...), $this->validated('role_ids')) : null);
    }
}
