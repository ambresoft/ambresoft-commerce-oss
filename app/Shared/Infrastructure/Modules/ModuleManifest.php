<?php

namespace App\Shared\Infrastructure\Modules;

/**
 * Add-on manifest, contracts 2, 3 and 4: a Composer-format `composer.json` whose `extra.cms-module` object describes the add-on.
 * Reading a manifest never executes add-on code. Identity, version, description, author, PSR-4 autoloading and
 * dependency constraints use the standard Composer keys; CMS-specific metadata lives in `extra.cms-module`.
 */
final readonly class ModuleManifest
{
    /** Contract 3 adds the public catalog and storefront widgets. Declaring it makes a CMS without them refuse the add-on. */
    public const CONTRACTS = [2, 3, 4];

    /** Add-on icon: a small package-relative image (extension => media type). */
    public const ICON_TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public const ICON_MAX_BYTES = 262144;

    private const NAME = '~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D';

    private const CLASS_NAME = '/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+$/D';

    private const PREFIX = '/^(?:[A-Z][A-Za-z0-9_]*\\\\)+$/D';

    private const CONTRIBUTION = '/^[a-z][a-z0-9-]{1,39}$/D';

    /** Namespaces owned by the CMS or its libraries; an add-on can never claim (or nest inside) them. */
    private const RESERVED_PREFIXES = ['App\\', 'Tests\\', 'Database\\', 'Illuminate\\', 'Laravel\\', 'Symfony\\', 'Composer\\', 'Inertia\\', 'Psr\\', 'Carbon\\', 'GuzzleHttp\\', 'League\\', 'Monolog\\', 'PHPUnit\\', 'Doctrine\\', 'Brick\\', 'Ramsey\\', 'Dotenv\\', 'Termwind\\', 'NunoMaduro\\', 'Mockery\\', 'Faker\\'];

    /**
     * @param  array<string, string>  $requires  Add-on name => version constraint
     * @param  array<string, string>  $autoload  PSR-4 prefix => directory relative to the package root
     * @param  array<string, mixed>  $contributes  Extension point => declared contributions
     * @param  list<string>  $uninstallClears  Own tables emptied on uninstall, for data that must not survive a reinstall
     */
    private function __construct(
        public string $name,
        public string $version,
        public string $title,
        public string $description,
        public string $author,
        public string $cms,
        public string $provider,
        public array $requires,
        public array $autoload,
        public ?string $migrations,
        public array $contributes,
        public ?string $icon = null,
        public array $uninstallClears = [],
    ) {}

    /**
     * @param  array<string, mixed>  $composer
     *
     * @throws AddonException
     */
    public static function fromComposer(array $composer): self
    {
        $name = $composer['name'] ?? null;
        if (! is_string($name) || strlen($name) > 100 || preg_match(self::NAME, $name) !== 1) {
            throw new AddonException('addons.error_manifest_name');
        }
        $metadata = $composer['extra']['cms-module'] ?? null;
        if (! is_array($metadata) || ! in_array($metadata['contract'] ?? null, self::CONTRACTS, true)) {
            throw new AddonException('addons.error_manifest_contract', ['name' => $name, 'contract' => implode(', ', self::CONTRACTS)]);
        }
        if (! empty($composer['extra']['laravel']['providers'])) {
            throw new AddonException('addons.error_manifest_discovery', ['name' => $name]);
        }
        $version = $composer['version'] ?? null;
        if (! is_string($version) || ! VersionConstraint::validVersion($version)) {
            throw new AddonException('addons.error_manifest_version', ['name' => $name]);
        }
        $title = self::text($metadata['title'] ?? null, 100);
        if ($title === null || $title === '') {
            throw new AddonException('addons.error_manifest_title', ['name' => $name]);
        }
        $description = self::text($composer['description'] ?? '', 500) ?? throw new AddonException('addons.error_manifest_text', ['name' => $name]);
        $author = self::text(is_array($composer['authors'] ?? null) ? ($composer['authors'][0]['name'] ?? '') : '', 100) ?? throw new AddonException('addons.error_manifest_text', ['name' => $name]);
        $cms = $metadata['cms'] ?? null;
        if (! is_string($cms) || ! VersionConstraint::valid($cms)) {
            throw new AddonException('addons.error_manifest_cms', ['name' => $name]);
        }

        $psr4 = $composer['autoload']['psr-4'] ?? null;
        if (! is_array($psr4) || $psr4 === [] || array_is_list($psr4)) {
            throw new AddonException('addons.error_manifest_autoload', ['name' => $name]);
        }
        $autoload = [];
        foreach ($psr4 as $prefix => $path) {
            $prefix = (string) $prefix;
            if (preg_match(self::PREFIX, $prefix) !== 1 || ! is_string($path) || ($path = self::relative($path)) === null) {
                throw new AddonException('addons.error_manifest_autoload', ['name' => $name]);
            }
            foreach (self::RESERVED_PREFIXES as $reserved) {
                if (str_starts_with($prefix, $reserved) || str_starts_with($reserved, $prefix)) {
                    throw new AddonException('addons.error_manifest_namespace', ['name' => $name, 'namespace' => rtrim($prefix, '\\')]);
                }
            }
            $autoload[$prefix] = $path;
        }

        $provider = $metadata['provider'] ?? null;
        if (! is_string($provider) || preg_match(self::CLASS_NAME, $provider) !== 1 || self::prefixOf($autoload, $provider) === null) {
            throw new AddonException('addons.error_manifest_provider', ['name' => $name]);
        }

        $requiresList = $metadata['requires'] ?? [];
        if (! is_array($requiresList) || ! array_is_list($requiresList)) {
            throw new AddonException('addons.error_manifest_requires', ['name' => $name]);
        }
        $requires = [];
        foreach ($requiresList as $dependency) {
            $constraint = is_string($dependency) ? ($composer['require'][$dependency] ?? null) : null;
            if (! is_string($dependency) || preg_match(self::NAME, $dependency) !== 1 || $dependency === $name
                || ! is_string($constraint) || trim($constraint) === '' || ! VersionConstraint::valid($constraint) || isset($requires[$dependency])) {
                throw new AddonException('addons.error_manifest_requires', ['name' => $name]);
            }
            $requires[$dependency] = trim($constraint);
        }

        $migrations = $metadata['migrations'] ?? null;
        if ($migrations !== null && (! is_string($migrations) || ($migrations = self::relative($migrations)) === null || $migrations === '')) {
            throw new AddonException('addons.error_manifest_migrations', ['name' => $name]);
        }

        $contributes = $metadata['contributes'] ?? [];
        if (! is_array($contributes) || ($contributes !== [] && array_is_list($contributes))) {
            throw new AddonException('addons.error_manifest_contributes', ['name' => $name]);
        }
        foreach (array_keys($contributes) as $point) {
            if (preg_match(self::CONTRIBUTION, (string) $point) !== 1) {
                throw new AddonException('addons.error_manifest_contributes', ['name' => $name]);
            }
        }

        $icon = $metadata['icon'] ?? null;
        if ($icon !== null && (! is_string($icon) || ($icon = self::relative($icon)) === null || ! isset(self::ICON_TYPES[strtolower(pathinfo($icon, PATHINFO_EXTENSION))]))) {
            throw new AddonException('addons.error_manifest_icon', ['name' => $name]);
        }

        $clears = $metadata['uninstall-clears'] ?? [];
        $owned = '/^'.preg_quote((string) preg_replace('/[^a-z0-9]+/', '_', $name), '/').'_[a-z0-9_]{1,40}$/D';
        if (! is_array($clears) || ! array_is_list($clears) || array_filter($clears, fn (mixed $table): bool => ! is_string($table) || preg_match($owned, $table) !== 1) !== []) {
            throw new AddonException('addons.error_manifest_uninstall_clears', ['name' => $name]);
        }

        return new self($name, $version, $title, $description, $author, trim($cms), $provider, $requires, $autoload, $migrations, $contributes, $icon === '' ? null : $icon, $clears);
    }

    /** Reads and validates `composer.json` in a package directory. */
    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/').'/composer.json';
        if (! is_file($file) || is_link($file) || filesize($file) > 65536 || ($json = file_get_contents($file)) === false) {
            throw new AddonException('addons.error_manifest_missing');
        }
        try {
            $composer = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AddonException('addons.error_manifest_json');
        }
        if (! is_array($composer)) {
            throw new AddonException('addons.error_manifest_json');
        }

        $manifest = self::fromComposer($composer);
        if ($manifest->icon !== null && ! self::usableIcon(rtrim($directory, '/').'/'.$manifest->icon)) {
            throw new AddonException('addons.error_manifest_icon', ['name' => $manifest->name]);
        }

        return $manifest;
    }

    /** A regular, small image file; an SVG must not carry scripts, handlers or embedded HTML. */
    public static function usableIcon(string $file): bool
    {
        if (! is_file($file) || is_link($file) || filesize($file) < 1 || filesize($file) > self::ICON_MAX_BYTES) {
            return false;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            $svg = (string) file_get_contents($file);

            return str_contains($svg, '<svg') && preg_match('/<script|<foreignObject|<iframe|<embed|<object|javascript:|\son[a-z]+\s*=|<!ENTITY|<!DOCTYPE/i', $svg) !== 1;
        }

        return true;
    }

    public function compatible(string $cmsVersion = CmsVersion::CURRENT): bool
    {
        return VersionConstraint::satisfies($cmsVersion, $this->cms);
    }

    /** The package-relative file that PSR-4 maps a class of this add-on to, or null when it is outside its namespaces. */
    public function classFile(string $class): ?string
    {
        if (preg_match(self::CLASS_NAME, $class) !== 1 || ($prefix = self::prefixOf($this->autoload, $class)) === null) {
            return null;
        }
        $base = $this->autoload[$prefix];

        return ($base === '' ? '' : $base.'/').str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }

    /** Owned migration files must start with a timestamp followed by the add-on's name, so names never collide. */
    public function migrationPrefix(): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', $this->name).'_';
    }

    /** @param array<string, string> $autoload */
    private static function prefixOf(array $autoload, string $class): ?string
    {
        foreach (array_keys($autoload) as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /** A package-relative path without traversal, normalised without a trailing slash. */
    private static function relative(string $path): ?string
    {
        $path = rtrim(trim($path), '/');
        if ($path === '' || $path === '.') {
            return '';
        }
        if (strlen($path) > 200 || preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_./-]*$~D', $path) !== 1) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 ? null : $value;
    }
}
