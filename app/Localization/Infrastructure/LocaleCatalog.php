<?php

namespace App\Localization\Infrastructure;

use App\Localization\Infrastructure\Persistence\LocaleRecord;
use Illuminate\Support\Collection;

final class LocaleCatalog
{
    /** @return Collection<int, LocaleRecord> */
    public function supported(): Collection
    {
        return LocaleRecord::whereIn('code', array_keys(config('languages.pool')))->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return Collection<int, LocaleRecord> */
    public function active(): Collection
    {
        return LocaleRecord::whereIn('code', array_keys(config('languages.pool')))->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (LocaleRecord $locale): bool => preg_match('/^[a-z]{2,3}$/D', $locale->code) === 1)->values();
    }

    public function defaultCode(): string
    {
        return $this->active()->where('is_default', true)->pluck('code')->first() ?? 'en';
    }

    public function forPreference(?int $id): string
    {
        return $this->active()->where('id', $id)->pluck('code')->first() ?? $this->defaultCode();
    }
}
