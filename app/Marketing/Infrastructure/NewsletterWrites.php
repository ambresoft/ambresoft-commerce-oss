<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Authorization, audit and concurrency helpers shared by the newsletter write services. */
trait NewsletterWrites
{
    private function authorize(string $actor, string $ability = 'newsletters.manage'): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $entity, string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation($entity, $actor, $operation);
    }

    private function stale(): ValidationException
    {
        return ValidationException::withMessages(['version' => __('newsletters.stale')]);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $input */
    private function flag(array $input, string $key, bool $default): bool
    {
        return array_key_exists($key, $input) ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }

    /**
     * Existing IDs among the submitted ones; any unknown ID is a validation error.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $errors
     * @return list<string>
     */
    private function existing(array $input, string $field, string $table, array &$errors): array
    {
        $values = is_array($input[$field] ?? null) ? array_values(array_unique(array_filter(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $input[$field]), ctype_digit(...)))) : [];
        if ($values === []) {
            return [];
        }
        $found = DB::table($table)->whereIn('id', $values)->pluck('id')->map(strval(...))->all();
        if (count($found) !== count($values)) {
            $errors[$field] = __('newsletters.choice_invalid');
        }

        return $found;
    }
}
