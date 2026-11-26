<?php

namespace App\Settings\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiSettings
{
    /** Selectable models with their display names, newest first. Saved older choices stay valid. */
    public const MODELS = ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-sonnet-4-6' => 'Claude Sonnet 4.6', 'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5'];

    /** Models that always think first; short drafting tasks run them at low effort to bound time and tokens. */
    public const THINKING_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    /** Models that hand a declined request to Anthropic's fallback model instead of failing. */
    public const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $row = DB::table('ai_settings')->where('id', 1)->firstOrFail();

        return ['enabled' => (bool) $row->enabled, 'has_key' => $row->api_key !== null, 'model' => $row->model, 'max_tokens' => (int) $row->max_tokens, 'hourly_limit' => (int) $row->hourly_limit, 'daily_limit' => (int) $row->daily_limit, 'version' => (int) $row->lock_version];
    }

    public function key(): string
    {
        $key = DB::table('ai_settings')->where('id', 1)->value('api_key');
        if ($key === null) {
            throw ValidationException::withMessages(['ai' => __('ai.not_configured')]);
        }
        try {
            return Crypt::decryptString($key);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['ai' => __('ai.key_unreadable')]);
        }
    }

    /** @param array<string, mixed> $input */
    public function save(string $actor, array $input): void
    {
        app(AuditLogger::class)->operation('ai_settings', $actor, fn () => DB::transaction(function () use ($actor, $input): void {
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
            app(AuditContext::class)->actor($actor);
            $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/D'], 'clear_key' => ['sometimes', 'boolean'], 'model' => ['required', Rule::in(array_keys(self::MODELS))], 'max_tokens' => ['required', 'integer', 'between:256,16000'], 'hourly_limit' => ['required', 'integer', 'between:1,1000'], 'daily_limit' => ['sometimes', 'required', 'integer', 'between:1,100000']])->validate();
            $row = DB::table('ai_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ((int) $row->lock_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => __('ai.stale')]);
            }
            $key = ! empty($data['clear_key']) ? null : $row->api_key;
            if (! empty($data['api_key'])) {
                $key = Crypt::encryptString($data['api_key']);
            }
            if ($data['enabled'] && $key === null) {
                throw ValidationException::withMessages(['api_key' => __('ai.key_required')]);
            }
            DB::table('ai_settings')->where('id', 1)->update(['enabled' => (bool) $data['enabled'], 'api_key' => $key, 'model' => $data['model'], 'max_tokens' => $data['max_tokens'], 'hourly_limit' => $data['hourly_limit'], 'daily_limit' => $data['daily_limit'] ?? $row->daily_limit, 'lock_version' => (int) $data['version'] + 1, 'updated_by' => $actor, 'updated_at' => now()]);
            if ($key !== $row->api_key) {
                app(AuditLogger::class)->record(new AuditEvent('ai_settings.credentials_changed', 'ai_settings', '1', metadata: ['key_configured' => $key !== null]));
            }
        }));
    }
}
