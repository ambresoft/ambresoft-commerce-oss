<?php

namespace App\Geography\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Validation\ValidationException;

final class RegionInput
{
    /**
     * @param  array<string, string>  $names
     * @return array{string, array<string, string>}
     */
    public function validate(string $code, array $names, string $status): array
    {
        $locales = app(LocaleCatalog::class);
        $default = $locales->defaultCode();
        $code = strtoupper(trim($code));
        $clean = [];
        foreach ($names as $locale => $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $clean[(string) $locale] = $name;
            }
        }
        $errors = [];
        if (preg_match('/^[A-Z0-9]{1,10}$/D', $code) !== 1) {
            $errors['code'] = __('geography.code_invalid');
        }
        if (! in_array($status, ['active', 'disabled'], true)) {
            $errors['status'] = __('geography.status_invalid');
        }
        if (array_diff(array_keys($clean), $locales->active()->pluck('code')->all()) !== []) {
            $errors['names'] = __('geography.name_language_invalid');
        }
        if (! isset($clean[$default])) {
            $errors['names.'.$default] = __('geography.name_required');
        }
        foreach ($clean as $locale => $name) {
            if (mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                $errors['names.'.$locale] = __('geography.name_invalid');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$code, $clean];
    }
}
