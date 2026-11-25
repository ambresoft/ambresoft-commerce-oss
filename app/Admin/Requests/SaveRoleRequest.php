<?php

namespace App\Admin\Requests;

use App\Authorization\Application\Command\SaveRoleData;
use App\Authorization\Infrastructure\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->route('role') === null ? 'roles.create' : 'roles.update') && Gate::allows('roles.assign-permissions');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', Rule::unique('roles', 'slug')->ignore($this->route('role'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_values(array_diff(array_keys(app(PermissionRegistry::class)->all()), ['audit.view', 'account-security.view'])))],
        ];
    }

    public function toData(): SaveRoleData
    {
        return new SaveRoleData($this->validated('name'), $this->validated('slug'), $this->validated('description'), $this->validated('permissions'));
    }
}
