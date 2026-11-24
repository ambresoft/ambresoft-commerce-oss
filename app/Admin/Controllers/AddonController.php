<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Payment\Infrastructure\PaymentSettings;
use App\Shared\Infrastructure\Modules\AddonCatalog;
use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Shared\Infrastructure\Modules\CmsVersion;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use App\Shipping\Application\Service\ShippingProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Add-ons: bundled first-party and uploaded third-party packages share one list and one lifecycle. */
final class AddonController
{
    public function index(Request $request, AddonManager $addons): Response
    {
        Gate::authorize('addons.manage');

        return Inertia::render('Admin/Addons/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('addons.title'), 'href' => null]],
            'addons' => $this->withSettings($addons->overview()), 'cmsVersion' => CmsVersion::CURRENT,
            'uploads' => ['enabled' => (bool) config('modules.uploads'), 'allowed' => Gate::allows('addons.upload'), 'maxMb' => (int) floor((int) config('modules.upload_max_kb') / 1024)],
        ]);
    }

    /** The add-on's declared icon, served inert: never sniffed, never allowed to run anything. */
    public function icon(string $vendor, string $name, AddonCatalog $catalog): HttpResponse
    {
        Gate::authorize('addons.manage');
        $package = $catalog->discover()[$vendor.'/'.$name] ?? null;
        $icon = $package === null ? null : $package['manifest']?->icon;
        abort_if($package === null || $icon === null, 404);
        $file = $package['path'].'/'.$icon;
        abort_unless(ModuleManifest::usableIcon($file), 404);

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => ModuleManifest::ICON_TYPES[strtolower(pathinfo($icon, PATHINFO_EXTENSION))],
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function upload(Request $request, AddonManager $addons): RedirectResponse
    {
        Gate::authorize('addons.upload');
        $data = $request->validate([
            'package' => ['required', 'file', 'extensions:zip', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream', 'max:'.(int) config('modules.upload_max_kb')],
            'upgrade' => ['sometimes', 'boolean'],
        ]);
        $name = $this->run(fn (): string => $addons->upload((string) $request->user()->id, $data['package']->getRealPath(), (bool) ($data['upgrade'] ?? false)), 'package');

        return redirect('/admin/addons')->with('status', __('addons.uploaded_done', ['name' => $name]));
    }

    public function install(Request $request, AddonManager $addons, string $vendor, string $name): RedirectResponse
    {
        return $this->lifecycle($request, fn (string $actor, string $addon) => $addons->installBundled($actor, $addon), $vendor, $name, 'addons.installed');
    }

    public function enable(Request $request, AddonManager $addons, string $vendor, string $name): RedirectResponse
    {
        return $this->lifecycle($request, fn (string $actor, string $addon) => $addons->enable($actor, $addon), $vendor, $name, 'addons.enabled');
    }

    public function disable(Request $request, AddonManager $addons, string $vendor, string $name): RedirectResponse
    {
        return $this->lifecycle($request, fn (string $actor, string $addon) => $addons->disable($actor, $addon), $vendor, $name, 'addons.disabled');
    }

    public function upgrade(Request $request, AddonManager $addons, string $vendor, string $name): RedirectResponse
    {
        return $this->lifecycle($request, fn (string $actor, string $addon) => $addons->upgrade($actor, $addon), $vendor, $name, 'addons.upgraded');
    }

    public function uninstall(Request $request, AddonManager $addons, string $vendor, string $name): RedirectResponse
    {
        return $this->lifecycle($request, fn (string $actor, string $addon) => $addons->uninstall($actor, $addon), $vendor, $name, 'addons.uninstalled');
    }

    /**
     * Links each enabled add-on to the configuration of what it contributes (its payment methods), for staff allowed to
     * configure them.
     *
     * @param  list<array<string, mixed>>  $addons
     * @return list<array<string, mixed>>
     */
    private function withSettings(array $addons): array
    {
        $allowed = Gate::allows('payment-methods.manage');
        $carriers = Gate::allows('shipping-carriers.manage');
        $shipping = app(ShippingProviderRegistry::class);
        $registry = app(PaymentMethodRegistry::class);
        $settings = app(PaymentSettings::class);
        if ($allowed) {
            $settings->ensure();
        }

        return array_map(function (array $addon) use ($allowed, $registry, $settings, $carriers, $shipping): array {
            $links = [];
            foreach ($carriers && $addon['status'] === 'enabled' ? ($addon['contributes']['shipping-providers'] ?? []) : [] as $provider) {
                if ($shipping->owner($provider) === $addon['name']) {
                    $links[] = ['name' => $shipping->get($provider)->name(), 'href' => '/admin/shipping-carriers/'.$provider];
                }
            }
            foreach ($allowed && $addon['status'] === 'enabled' ? ($addon['contributes']['payment-methods'] ?? []) : [] as $code) {
                $row = $registry->owner($code) === $addon['name'] ? $settings->row($code) : null;
                if ($row !== null) {
                    $links[] = ['name' => $settings->name((string) $row->id, app()->getLocale()), 'href' => '/admin/payment-methods/'.$code.'/edit'];
                }
            }
            if ($addon['status'] === 'enabled' && isset($addon['contributes']['settings'])) {
                $links[] = ['name' => $addon['title'], 'href' => '/admin/addons/'.$addon['name'].'/settings'];
            }
            if ($addon['status'] === 'enabled' && ($addon['contributes']['search-providers'] ?? []) !== [] && Gate::allows('search.manage')) {
                $links[] = ['name' => __('search.title'), 'href' => '/admin/settings/search'];
            }
            if ($addon['status'] === 'enabled' && $addon['name'] === 'ambresoft/gdpr-cookies' && Gate::allows('ambresoft.gdpr-cookies.view')) {
                $links[] = ['name' => __('cms-gdpr::gdpr.title'), 'href' => '/admin/addons/ambresoft/gdpr-cookies'];
            }
            if ($addon['status'] === 'enabled' && $addon['name'] === 'ambresoft/product-feeds' && Gate::allows('ambresoft.product-feeds.view')) {
                $links[] = ['name' => __('cms-product-feeds::ui.title'), 'href' => '/admin/addons/ambresoft/product-feeds'];
            }
            if ($addon['status'] === 'enabled' && $addon['name'] === 'ambresoft/sitemap' && Gate::allows('ambresoft.sitemap.view')) {
                $links[] = ['name' => __('cms-sitemap::ui.title'), 'href' => '/admin/sitemap'];
            }

            return [...$addon, 'settings' => $links];
        }, $addons);
    }

    /** @param callable(string, string): mixed $operation */
    private function lifecycle(Request $request, callable $operation, string $vendor, string $name, string $message): RedirectResponse
    {
        $addon = $vendor.'/'.$name;
        $this->run(fn () => $operation((string) $request->user()->id, $addon), 'addon');

        return redirect('/admin/addons')->with('status', __($message, ['name' => $addon]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function run(callable $operation, string $field): mixed
    {
        try {
            return $operation();
        } catch (AddonException $e) {
            throw ValidationException::withMessages([$field => $e->translated()]);
        }
    }
}
