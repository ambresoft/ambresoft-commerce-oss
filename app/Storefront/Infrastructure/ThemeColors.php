<?php

namespace App\Storefront\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The store's brand colours. Only strict #rrggbb values are stored, so they are safe to emit as CSS variables. */
final class ThemeColors
{
    public const DEFAULTS = ['primary' => '#05ab7d', 'primary_hover' => '#079c72', 'on_primary' => '#ffffff', 'text' => '#333333', 'background' => '#f8f8f8', 'sale' => '#ff3d47'];

    /** @return array{primary:string,primary_hover:string,on_primary:string,text:string,background:string,sale:string} */
    public function all(): array
    {
        $row = DB::table('storefront_theme_settings')->where('id', 1)->first();
        $colors = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = $row->{$name.'_color'} ?? '';
            $colors[$name] = preg_match('/^#[0-9a-f]{6}$/D', (string) $value) === 1 ? $value : $default;
        }

        return $colors;
    }

    /** The sign-in header's own colour, or null while it follows the store's primary colour. */
    public function authHeader(): ?string
    {
        $value = DB::table('storefront_theme_settings')->where('id', 1)->value('auth_header_color');

        return preg_match('/^#[0-9a-f]{6}$/D', (string) $value) === 1 ? $value : null;
    }

    /** Black or white, whichever reads better on a #rrggbb background. */
    public static function textOn(string $background): string
    {
        [$r, $g, $b] = array_map(fn (int $c): float => ($c / 255) <= 0.03928 ? ($c / 255) / 12.92 : ((($c / 255) + 0.055) / 1.055) ** 2.4, [hexdec(substr($background, 1, 2)), hexdec(substr($background, 3, 2)), hexdec(substr($background, 5, 2))]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.4 ? '#111111' : '#ffffff';
    }

    public function version(): int
    {
        return (int) DB::table('storefront_theme_settings')->where('id', 1)->value('lock_version');
    }

    /** @param array<string,mixed> $input */
    public function save(string $actor, array $input): void
    {
        app(AuditLogger::class)->operation('storefront_theme', $actor, function () use ($actor, $input): void {
            DB::transaction(function () use ($actor, $input): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('layouts.manage');
                $rules = ['version' => ['required', 'integer', 'min:1']];
                foreach (array_keys(self::DEFAULTS) as $name) {
                    $rules[$name] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'];
                }
                // Optional: empty follows the store colour; left out keeps the saved one.
                $rules['auth_header'] = ['sometimes', 'nullable', 'string', 'regex:/^(#[0-9a-fA-F]{6})?$/D'];
                $data = Validator::make($input, $rules)->validate();
                $row = DB::table('storefront_theme_settings')->where('id', 1)->lockForUpdate()->first();
                if ((int) $row->lock_version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => __('layouts.stale')]);
                }
                $values = [];
                foreach (array_keys(self::DEFAULTS) as $name) {
                    $values[$name.'_color'] = strtolower($data[$name]);
                }
                if (array_key_exists('auth_header', $data)) {
                    $values['auth_header_color'] = ($data['auth_header'] ?? '') === '' ? null : strtolower($data['auth_header']);
                }
                DB::table('storefront_theme_settings')->where('id', 1)->update([...$values, 'lock_version' => $row->lock_version + 1, 'updated_at' => now()]);
            });
        });
    }
}
