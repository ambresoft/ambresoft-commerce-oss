<?php

namespace App\Settings\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * One structured-output Anthropic call on the merchant's own key, shared by every AI feature: the enabled check,
 * one call in flight per staff member, the shared hourly allowance and an `ai_calls` row (never the prompt,
 * response or key). Callers authorize and validate their own input first.
 */
final class AiClient
{
    /**
     * Turns a chat request from an editor into the provider conversation: earlier turns stay plain text and the
     * latest request travels with the editor's current state and the feature's context as reference data.
     *
     * @param  array<string, mixed>  $input  `messages` (alternating chat turns, first and last from the merchant) and `current`.
     * @param  array<string, mixed>  $context
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function conversation(array $input, array $context, int $currentBytes = 20000): array
    {
        $data = Validator::make($input, [
            'messages' => ['required', 'array', 'min:1', 'max:30'], 'messages.*' => ['array:role,content'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])], 'messages.*.content' => ['required', 'string', 'max:4000'],
            'current' => ['nullable', 'array'],
        ])->validate();
        $messages = array_values($data['messages']);
        if ($messages[0]['role'] !== 'user' || end($messages)['role'] !== 'user') {
            throw ValidationException::withMessages(['messages' => __('ai.chat_invalid')]);
        }
        $current = json_encode($data['current'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($current) > $currentBytes) {
            throw ValidationException::withMessages(['current' => __('ai.chat_invalid')]);
        }
        $last = array_pop($messages);

        return [
            ...array_map(fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']], $messages),
            ['role' => 'user', 'content' => json_encode(['request' => $last['content'], 'ui_language' => app()->getLocale(), ...$context, 'current' => json_decode($current, true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * @template T
     *
     * @param  string  $messages  Translation prefix for the feature's errors, e.g. `faqs.ai_`.
     * @param  list<array{role: 'user'|'assistant', content: string}>  $conversation
     * @param  array<string, mixed>  $schema  JSON schema the response must follow.
     * @param  callable(mixed): T  $accept  Validates and normalises the decoded response; a ValidationException rejects it.
     * @return array{0: T, 1: string} The accepted result and the log ID.
     */
    public function structured(string $actor, string $feature, string $objectType, ?string $objectId, string $messages, string $system, array $conversation, array $schema, callable $accept, int $timeout = 45): array
    {
        $settings = app(AiSettings::class)->all();
        if (! $settings['enabled']) {
            throw ValidationException::withMessages(['ai' => __($messages.'not_configured')]);
        }
        $key = app(AiSettings::class)->key();
        // One in-flight call per staff member avoids races in the hourly allowance and duplicate charges.
        $lock = Cache::lock('ai-user:'.$actor, 60);
        abort_unless($lock->get(), 429, __($messages.'busy'));
        try {
            $limitKey = 'ai-hour:'.$actor;
            abort_if(RateLimiter::tooManyAttempts($limitKey, $settings['hourly_limit']), 429, __($messages.'rate_limited'));
            RateLimiter::hit($limitKey, 3600);
            $log = (string) DB::table('ai_calls')->insertGetId(['feature' => $feature, 'user_id' => $actor, 'object_type' => $objectType, 'object_id' => $objectId, 'model' => $settings['model']]);
            $started = microtime(true);
            $meta = [];
            $error = 'invalid_response';
            $result = null;
            try {
                $model = $settings['model'];
                $fallback = in_array($model, AiSettings::FALLBACK_MODELS, true);
                $response = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', ...($fallback ? ['anthropic-beta' => 'server-side-fallback-2026-07-01'] : [])])->acceptJson()->connectTimeout(5)->timeout($timeout)
                    ->withOptions(['allow_redirects' => false])->post('https://api.anthropic.com/v1/messages', [
                        'model' => $model, 'max_tokens' => $settings['max_tokens'], 'system' => $system, 'messages' => $conversation,
                        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema], ...(in_array($model, AiSettings::THINKING_MODELS, true) ? ['effort' => 'low'] : [])],
                        ...($fallback ? ['fallbacks' => 'default'] : []),
                    ]);
                $meta['http_status'] = $response->status();
                $meta['provider_request_id'] = substr((string) $response->header('request-id'), 0, 100) ?: null;
                $body = $response->json();
                $body = is_array($body) ? $body : [];
                foreach (['input_tokens', 'output_tokens'] as $token) {
                    $meta[$token] = isset($body['usage'][$token]) && is_int($body['usage'][$token]) && $body['usage'][$token] >= 0 ? $body['usage'][$token] : null;
                }
                if (! $response->successful()) {
                    $error = 'provider_error';
                } elseif (($body['stop_reason'] ?? null) !== 'end_turn') {
                    $error = ($body['stop_reason'] ?? '') === 'max_tokens' ? 'token_limit' : 'refused';
                } else {
                    $text = collect((array) ($body['content'] ?? []))->where('type', 'text')->pluck('text')->implode('');
                    $result = $accept(json_decode($text, true, 32, JSON_THROW_ON_ERROR));
                    $error = null;
                }
            } catch (ConnectionException) {
                $error = 'connection_error';
            } catch (JsonException|ValidationException) {
                $error = 'invalid_response';
            } finally {
                DB::table('ai_calls')->where('id', $log)->update([...$meta, 'status' => $error === null ? 'success' : 'error', 'error_code' => $error, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
            }
            if ($error !== null) {
                throw ValidationException::withMessages(['ai' => __($messages.'error_'.$error)]);
            }

            return [$result, $log];
        } finally {
            $lock->release();
        }
    }
}
