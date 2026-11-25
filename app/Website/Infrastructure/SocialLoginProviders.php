<?php

namespace App\Website\Infrastructure;

use App\Shared\Application\Command\SettingField;

/**
 * The sign-in providers a store can offer its customers. Only Google is supported for now; a provider declares the
 * fields it needs (the shared setting-field contract keeps the client secret encrypted and write-only) and the address
 * the storefront will receive its sign-in callback on, which the merchant registers with the provider.
 */
final class SocialLoginProviders
{
    /** Path the storefront serves each provider's callback on. */
    private const CALLBACK_PATH = '/auth/social/%s/callback';

    private const CONSOLE_URLS = ['google' => 'https://console.cloud.google.com/apis/credentials'];

    /** Brand names are not translated. */
    private const NAMES = ['google' => 'Google'];

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys(self::NAMES);
    }

    public function has(string $id): bool
    {
        return isset(self::NAMES[$id]);
    }

    public function name(string $id): string
    {
        return self::NAMES[$id] ?? $id;
    }

    public function consoleUrl(string $id): string
    {
        return self::CONSOLE_URLS[$id] ?? '';
    }

    /** The address to register as the authorized redirect URI with the provider. */
    public function callbackUrl(string $id): string
    {
        return rtrim((string) config('app.url'), '/').sprintf(self::CALLBACK_PATH, $id);
    }

    /** @return list<SettingField> */
    public function fields(string $id): array
    {
        return match ($id) {
            'google' => [
                new SettingField('client_id', 'text', 'social_login.client_id', 'social_login.client_id_hint', required: true, credential: true, maxLength: 255),
                new SettingField('client_secret', 'secret', 'social_login.client_secret', required: true, credential: true, maxLength: 200),
            ],
            default => [],
        };
    }

    /**
     * Provider-specific checks of submitted values.
     *
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => message
     */
    public function validate(string $id, array $values): array
    {
        $errors = [];
        if ($id === 'google' && ($values['client_id'] ?? '') !== '' && preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D', $values['client_id']) !== 1) {
            $errors['client_id'] = __('social_login.client_id_invalid');
        }

        return $errors;
    }
}
