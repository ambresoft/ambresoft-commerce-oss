<?php

namespace App\Website\Infrastructure;

use App\Shared\Infrastructure\Settings\FieldValues;
use Illuminate\Support\Facades\DB;

/** Read models of the social login screens. Credentials are never projected: the editor only learns whether one is stored. */
final class SocialLoginQueries
{
    public function __construct(private SocialLoginProviders $providers, private FieldValues $fields) {}

    /** @return list<array{id: string, provider: string, provider_name: string, name: string, active: bool, complete: bool, version: int}> */
    public function listing(): array
    {
        return DB::table('social_login_providers')->orderBy('provider')->orderBy('id')->get()->map(function (object $row): array {
            $values = $this->values($row);

            return [
                'id' => (string) $row->id, 'provider' => $row->provider, 'provider_name' => $this->providers->name($row->provider), 'name' => $row->name,
                'active' => (bool) $row->is_active, 'complete' => $this->fields->missing($this->providers->fields($row->provider), $values) === [], 'version' => (int) $row->lock_version,
            ];
        })->all();
    }

    /** @return array{id: string, provider: string, name: string, active: bool, version: int, fields: list<array<string, mixed>>}|null */
    public function editor(string $id): ?array
    {
        $row = ctype_digit($id) ? DB::table('social_login_providers')->where('id', $id)->first() : null;
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id, 'provider' => $row->provider, 'name' => $row->name, 'active' => (bool) $row->is_active, 'version' => (int) $row->lock_version,
            'fields' => $this->fields->editor($this->providers->fields($row->provider), $this->values($row)),
        ];
    }

    /**
     * Supported providers with their empty field definitions and the registration details a merchant needs.
     *
     * @return list<array{id: string, name: string, fields: list<array<string, mixed>>, callback_url: string, console_url: string}>
     */
    public function catalog(): array
    {
        return array_map(fn (string $id): array => [
            'id' => $id, 'name' => $this->providers->name($id), 'fields' => $this->fields->editor($this->providers->fields($id), []),
            'callback_url' => $this->providers->callbackUrl($id), 'console_url' => $this->providers->consoleUrl($id),
        ], $this->providers->ids());
    }

    /**
     * The sign-in buttons the storefront offers: active providers whose credentials are complete.
     *
     * @return list<array{provider: string, name: string}>
     */
    public function buttons(): array
    {
        $buttons = [];
        foreach (DB::table('social_login_providers')->where('is_active', true)->orderBy('provider')->get() as $row) {
            if ($this->providers->has($row->provider) && $this->fields->missing($this->providers->fields($row->provider), $this->values($row)) === []) {
                $buttons[] = ['provider' => $row->provider, 'name' => $this->providers->name($row->provider)];
            }
        }

        return $buttons;
    }

    /** @return array{client_id: string, client_secret: string}|null The active provider's credentials, server side only. */
    public function credentials(string $provider): ?array
    {
        $row = DB::table('social_login_providers')->where('provider', $provider)->where('is_active', true)->first();
        if ($row === null || ! $this->providers->has($provider)) {
            return null;
        }
        $values = $this->values($row);

        return $this->fields->missing($this->providers->fields($provider), $values) === [] ? ['client_id' => $values['client_id'], 'client_secret' => $values['client_secret']] : null;
    }

    /** @return array<string, string> */
    private function values(object $row): array
    {
        $plain = json_decode((string) $row->settings, true);

        return $this->fields->values($this->providers->fields($row->provider), is_array($plain) ? $plain : [], $this->fields->decrypt($row->secrets, 'social_login:'.$row->id));
    }
}
