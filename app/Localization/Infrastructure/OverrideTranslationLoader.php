<?php

namespace App\Localization\Infrastructure;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Laravel remains the dictionary; merchant overrides are a separate relational overlay. */
final class OverrideTranslationLoader implements Loader
{
    private ?bool $available = null;

    public function __construct(private readonly Loader $files) {}

    /** @return array<string, mixed> */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);
        // Console installs/migrations can use the file dictionary before the schema exists.
        if (! in_array($locale, array_keys(config('languages.pool')), true) || ! ($this->available ??= Schema::hasTable('ui_translation_overrides'))) {
            return $lines;
        }
        foreach (DB::table('ui_translation_overrides as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('l.code', $locale)->where('o.namespace', $namespace ?? '*')->where('o.group', $group)->get(['o.key', 'o.value']) as $row) {
            $this->setLine($lines, $row->key, $row->value);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }

    /** Preserve literal dotted wildcard attribute keys in Laravel validation catalogs.
     * @param  array<string, mixed>  $lines
     */
    private function setLine(array &$lines, string $key, string $value): void
    {
        if (array_key_exists($key, $lines) || ! str_contains($key, '.')) {
            $lines[$key] = $value;

            return;
        }
        [$first, $rest] = explode('.', $key, 2);
        $lines[$first] ??= [];
        $this->setLine($lines[$first], $rest, $value);
    }

    /** @return array<string, mixed> */
    public function original(string $locale, string $group, ?string $namespace = null): array
    {
        return $this->files->load($locale, $group, $namespace);
    }
}
