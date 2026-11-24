<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Website\Domain\Service\RedirectRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Writes SEO redirects. Every change validates the source (normalized, unique, not reserved), the destination
 * (internal path or safe external URL), refuses self-redirects and loops through enabled rules, authorizes
 * `seo-rules.manage`, is optimistic-locked and clears the storefront lookup after commit.
 */
final class RedirectAdministration
{
    public function __construct(private RedirectMap $map) {}

    /** @param array<string, mixed> $input source_path, destination, status_code, enabled */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        return app(AuditLogger::class)->operation('seo_redirect', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142715)');
            if ($id !== null) {
                $this->row($id, (int) $version);
            }
            $row = $this->validated($input, $id);
            try {
                if ($id === null) {
                    $id = (string) DB::table('seo_redirects')->insertGetId([...$row, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('seo_redirects')->where('id', $id)->update([...$row, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => (int) $version + 1]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['source_path' => __('seo_rules.source_taken')]);
            }
            DB::afterCommit(fn () => $this->map->forget());

            return $id;
        }));
    }

    /** Disabling keeps the rule; enabling checks it again against the other enabled rules. */
    public function setEnabled(string $actor, string $id, int $version, bool $enabled): void
    {
        $row = DB::table('seo_redirects')->where('id', $id)->first() ?? throw ValidationException::withMessages(['version' => __('seo_rules.stale')]);
        $this->save($actor, $id, $version, ['source_path' => $row->source_path, 'destination' => $row->destination, 'status_code' => $row->status_code, 'enabled' => $enabled]);
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('seo_redirect', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            $this->row($id, $version);
            DB::table('seo_redirects')->where('id', $id)->delete();
            DB::afterCommit(fn () => $this->map->forget());
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{source_path: string, destination: string, status_code: int, enabled: bool}
     */
    public function validated(array $input, ?string $id): array
    {
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $errors = [];

        // A full address of this store is accepted as its path.
        $sourceInput = $text($input['source_path'] ?? '');
        if (preg_match('#^https?://#i', $sourceInput) === 1 && strcasecmp((string) parse_url($sourceInput, PHP_URL_HOST), $host) === 0) {
            $sourceInput = (string) (parse_url($sourceInput, PHP_URL_PATH) ?? '/');
        }
        $source = $sourceInput === '' ? null : RedirectRules::path($sourceInput);
        if ($sourceInput === '') {
            $errors['source_path'] = __('seo_rules.source_required');
        } elseif ($source === null) {
            $errors['source_path'] = __('seo_rules.source_invalid');
        } elseif (RedirectRules::reserved($source)) {
            $errors['source_path'] = __('seo_rules.source_reserved');
        } elseif (DB::table('seo_redirects')->where('source_path', $source)->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            $errors['source_path'] = __('seo_rules.source_taken');
        }

        $destinationInput = $text($input['destination'] ?? '');
        $destination = $destinationInput === '' ? null : RedirectRules::destination($destinationInput, $host);
        if ($destinationInput === '') {
            $errors['destination'] = __('seo_rules.destination_required');
        } elseif ($destination === null) {
            $errors['destination'] = __('seo_rules.destination_invalid');
        }

        $code = filter_var($input['status_code'] ?? 301, FILTER_VALIDATE_INT);
        if (! in_array($code, RedirectRules::CODES, true)) {
            $errors['status_code'] = __('seo_rules.code_invalid');
        }
        $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $errors['enabled'] = __('seo_rules.choice_invalid');
        }

        if ($source !== null && $destination !== null && ! isset($errors['source_path'])) {
            $target = RedirectRules::internalPath($destination);
            if ($target === $source) {
                $errors['destination'] = __('seo_rules.self_redirect');
            } elseif ($enabled && $target !== null && $this->leadsBack($source, $target, $id)) {
                $errors['destination'] = __('seo_rules.loop');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['source_path' => (string) $source, 'destination' => (string) $destination, 'status_code' => (int) $code, 'enabled' => (bool) $enabled];
    }

    /** Whether following the enabled rules from the destination ever returns to the source. */
    private function leadsBack(string $source, string $target, ?string $id): bool
    {
        $rules = DB::table('seo_redirects')->where('enabled', true)->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->pluck('destination', 'source_path')->all();
        $seen = [];
        for ($hop = 0; $hop <= RedirectRules::MAX_HOPS; $hop++) {
            if ($target === $source) {
                return true;
            }
            if (isset($seen[$target]) || ! isset($rules[$target])) {
                // A loop that does not pass through this rule is refused when its own rules were saved.
                return isset($seen[$target]);
            }
            $seen[$target] = true;
            $next = RedirectRules::internalPath((string) $rules[$target]);
            if ($next === null) {
                return false;
            }
            $target = $next;
        }

        return true;
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('seo_redirects')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('seo_rules.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('seo_rules.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('seo-rules.manage');
    }
}
