<?php

namespace App\Storefront\Application\Service;

use App\Storefront\Application\Contract\BlockType;
use LogicException;

final class StorefrontRegistry
{
    /** @var array<string, BlockType> */
    private array $blocks = [];

    /** @var array<string, array<string, mixed>> */
    private array $themes = [];

    /** @var array<string, array<string, mixed>> */
    private array $pages = [];

    /** @var array<string, string> Trusted package CSS, keyed by its owning add-on. */
    private array $stylesheets = [];

    /** @var array<string, array{scheme: string, background: string}> */
    private array $documentPalettes = [];

    /** @var array<string, array{src: string, title: string}> Widget frames, keyed by their owning add-on. */
    private array $widgets = [];

    /** @var array<string, array{slug: string, src: string, title: string}> Frame pages, keyed by their owning add-on. */
    private array $framePages = [];

    /** @var array{addon: string, config: \Closure(): ?array{container: string, dataLayer: string, events: array<string, string>}}|null */
    private ?array $googleTagManager = null;

    /** @var array{addon: string, config: \Closure(): ?array{measurementId: string, ecommerce: bool}}|null */
    private ?array $googleAnalytics = null;

    /** @var array{addon: string, config: \Closure(): ?array<string, mixed>}|null */
    private ?array $consentManager = null;

    /**
     * Declares one widget for an add-on: an external HTTPS document shown in a cross-origin sandboxed frame. Its code
     * never runs in the storefront's origin, so it cannot read pages, cookies or account data. Anything it needs goes in
     * the address, which reaches every visitor's browser and must never hold a secret. The CMS fills {locale}, {primary}
     * and {on_primary} in the address, so the frame can follow the visitor's language and the store's colours.
     *
     * @param  string  $title  What the frame is, for screen readers. A translation key is translated for each visit
     */
    public function widget(string $addon, string $src, string $title): void
    {
        if (isset($this->widgets[$addon]) || ! $this->validFrame($addon, $src, $title)) {
            throw new LogicException('Storefront widgets require a unique add-on owner, a title and an HTTPS address without credentials.');
        }
        $this->widgets[$addon] = ['src' => $src, 'title' => $title];
    }

    /**
     * Declares one full page for an add-on, at a path of one or more slugs: the store's header and navigation with a
     * cross-origin sandboxed frame below them. The rules of widget() apply. Paths that already mean something win.
     *
     * @param  string  $title  The page's title, also announced by screen readers
     */
    public function framePage(string $addon, string $slug, string $src, string $title): void
    {
        if (isset($this->framePages[$addon]) || ! $this->validFrame($addon, $src, $title) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new LogicException('Storefront frame pages require a unique add-on owner, a slug, a title and an HTTPS address without credentials.');
        }
        foreach ($this->framePages as $page) {
            if ($page['slug'] === $slug) {
                throw new LogicException('Storefront frame pages require a unique slug.');
            }
        }
        $this->framePages[$addon] = ['slug' => $slug, 'src' => $src, 'title' => $title];
    }

    /** @return array<string, array{slug: string, src: string, title: string}> */
    public function framePages(): array
    {
        return $this->framePages;
    }

    private function validFrame(string $addon, string $src, string $title): bool
    {
        $url = parse_url($src);

        return preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $addon) === 1 && strlen($src) <= 2000 && preg_match('/[\s\x00-\x1f]/', $src) !== 1
            && is_array($url) && ($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') !== '' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment'])
            && trim($title) !== '' && strlen($title) <= 200 && preg_match('/[\x00-\x1f]/', $title) !== 1;
    }

    /** @return array<string, array{src: string, title: string}> */
    public function widgets(): array
    {
        return $this->widgets;
    }

    public function documentPalette(string $addon, string $scheme, string $background): void
    {
        if (! isset($this->stylesheets[$addon]) || ! in_array($scheme, ['light', 'dark'], true) || ! preg_match('/^#[0-9a-f]{6}$/D', $background)) {
            throw new LogicException('Document palettes require a registered stylesheet, a color scheme and a hex background.');
        }
        $this->documentPalettes[$addon] = ['scheme' => $scheme, 'background' => $background];
    }

    /** @return array<string, array{scheme: string, background: string}> */
    public function documentPalettes(): array
    {
        return $this->documentPalettes;
    }

    public function stylesheet(string $addon, string $css): void
    {
        if (! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $addon) || isset($this->stylesheets[$addon])) {
            throw new LogicException('Storefront stylesheets require a unique add-on owner.');
        }
        $this->stylesheets[$addon] = $css;
    }

    /** @return array<string, string> */
    public function stylesheets(): array
    {
        return $this->stylesheets;
    }

    /**
     * The one Google Tag Manager container for public storefront pages (A147). The resolver runs per request, so saved
     * settings apply on the next visit; it returns null while no container is configured.
     *
     * @param  \Closure(): ?array{container: string, dataLayer: string, events: array<string, string>}  $config
     */
    public function googleTagManager(string $addon, \Closure $config): void
    {
        if (! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $addon) || $this->googleTagManager !== null) {
            throw new LogicException('Google Tag Manager requires one add-on owner.');
        }
        $this->googleTagManager = ['addon' => $addon, 'config' => $config];
    }

    /** @return array{addon: string, config: \Closure(): ?array{container: string, dataLayer: string, events: array<string, string>}}|null */
    public function googleTagManagerSource(): ?array
    {
        return $this->googleTagManager;
    }

    /**
     * The one Google Analytics 4 property for public storefront pages (A148), under the same page rules as Google Tag
     * Manager. The resolver runs per request and returns null while no measurement ID is configured.
     *
     * @param  \Closure(): ?array{measurementId: string, ecommerce: bool}  $config
     */
    public function googleAnalytics(string $addon, \Closure $config): void
    {
        if (! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $addon) || $this->googleAnalytics !== null) {
            throw new LogicException('Google Analytics requires one add-on owner.');
        }
        $this->googleAnalytics = ['addon' => $addon, 'config' => $config];
    }

    /** @return array{addon: string, config: \Closure(): ?array{measurementId: string, ecommerce: bool}}|null */
    public function googleAnalyticsSource(): ?array
    {
        return $this->googleAnalytics;
    }

    /**
     * The one cookie consent manager (A149). While it is active, core tags wait for consent and the resolver's banner
     * configuration (texts, categories, declared cookies, consented scripts) reaches every storefront page.
     *
     * @param  \Closure(): ?array<string, mixed>  $config
     */
    public function consentManager(string $addon, \Closure $config): void
    {
        if (! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $addon) || $this->consentManager !== null) {
            throw new LogicException('A cookie consent manager requires one add-on owner.');
        }
        $this->consentManager = ['addon' => $addon, 'config' => $config];
    }

    /** @return array{addon: string, config: \Closure(): ?array<string, mixed>}|null */
    public function consentManagerSource(): ?array
    {
        return $this->consentManager;
    }

    public function block(BlockType $block): void
    {
        $this->unique($block->id(), $this->blocks);
        $this->blocks[$block->id()] = $block;
    }

    /** @param array<string,mixed> $definition */
    public function theme(string $id, array $definition): void
    {
        $this->unique($id, $this->themes);
        $this->themes[$id] = $definition;
    }

    /** Add trusted template identifiers to an existing theme without replacing its metadata.
     * @param list<string> $templates */
    public function templates(string $theme, array $templates): void
    {
        if (! isset($this->themes[$theme])) {
            throw new LogicException('Register a theme before extending its templates.');
        }
        foreach ($templates as $id) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id)) {
                throw new LogicException('Invalid template identifier.');
            }
        }
        $this->themes[$theme]['templates'] = array_values(array_unique([...$this->themes[$theme]['templates'], ...$templates]));
    }

    /** @param array<string,mixed> $definition */
    public function page(string $id, array $definition): void
    {
        $this->unique($id, $this->pages);
        $this->pages[$id] = $definition;
    }

    /** @param array<string,mixed> $entries */
    private function unique(string $id, array $entries): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) || isset($entries[$id])) {
            throw new LogicException('Storefront registrations require unique stable identifiers.');
        }
    }

    public function type(string $id): ?BlockType
    {
        return $this->blocks[$id] ?? null;
    }

    /** @return array<string,BlockType> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return array<string,array<string,mixed>> */
    public function pages(): array
    {
        return $this->pages;
    }

    /** Prefix of layouts that merchants add themselves; they behave like the homepage (shared regions plus one content region). */
    public const CUSTOM_PREFIX = 'custom.';

    public function isCustom(string $page): bool
    {
        return str_starts_with($page, self::CUSTOM_PREFIX) && ! isset($this->pages[$page]);
    }

    /** @return array<string,mixed>|null Registered page or a merchant-added layout; null for anything else. */
    public function definition(string $page): ?array
    {
        if ($this->isCustom($page)) {
            return ['label' => null, 'regions' => ['content'], 'inherits' => true, 'custom' => true];
        }

        return $this->pages[$page] ?? null;
    }

    /** The registered page whose block rules a layout follows (block types list the pages they may be placed on). */
    public function contentPage(string $page): string
    {
        return $this->isCustom($page) ? 'homepage' : $page;
    }

    /** @return array<string,mixed> */
    public function activeTheme(string $id): array
    {
        return $this->themes[$id] ?? $this->themes['default'];
    }
}
