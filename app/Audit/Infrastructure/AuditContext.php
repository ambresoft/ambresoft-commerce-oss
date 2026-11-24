<?php

namespace App\Audit\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    private bool $failed = false;

    public function failureRecorded(): bool
    {
        return $this->failed;
    }

    public function markFailure(): void
    {
        $this->failed = true;
    }

    /** @param array<string, mixed> $data */
    public function start(array $data = []): void
    {
        $this->failed = false;
        $this->data = ['request_id' => (string) Str::uuid(), 'source' => 'system', ...$data];
        $this->write();
    }

    public function actor(?string $id): void
    {
        // Explicit use-case actor beats ambient authentication, including console callers.
        if (($this->data['actor_id'] ?? null) === $id && $this->data !== []) {
            $this->write();

            return;
        }
        if ($this->data === []) {
            $this->start();
        }
        $user = $id === null ? null : DB::table('users')->where('id', $id)->first(['email']);
        $this->data['actor_id'] = $id;
        $this->data['actor_email'] = config('audit.actor_email') ? $user?->email : null;
        $this->data['actor_roles'] = $id === null ? [] : DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('user_id', $id)->orderBy('roles.id')->limit(50)->pluck('roles.slug')->all();
        $this->write();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function clear(): void
    {
        $this->data = [];
        $this->failed = false;
        $this->write();
    }

    private function write(): void
    {
        DB::select("SELECT set_config('cms.audit_context', ?, false)", [json_encode((object) $this->data, JSON_THROW_ON_ERROR)]);
    }
}
