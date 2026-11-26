<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\AiSettings;
use App\Settings\Infrastructure\CheckoutSettings;
use App\Settings\Infrastructure\GeneralOptions;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Geography\Countries;
use App\Storefront\Infrastructure\InvoiceVerification;
use App\Storefront\Infrastructure\ThemeColors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GeneralSettingsController
{
    public function show(Request $request, AdminNavigation $navigation, GeneralSettings $settings): Response
    {
        Gate::authorize('settings.manage');
        /** @var UserRecord $user */
        $user = $request->user();

        return Inertia::render('Admin/Settings/General', [
            'navigation' => $navigation->forUser($user),
            'breadcrumbs' => [
                ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
                ['label' => __('settings.title'), 'href' => null],
            ],
            'settings' => $settings->all(),
            'options' => $this->categoryOptions($request, $settings),
            'storefrontColors' => ['colors' => app(ThemeColors::class)->all(), 'auth_header' => app(ThemeColors::class)->authHeader() ?? '', 'defaults' => ThemeColors::DEFAULTS, 'version' => app(ThemeColors::class)->version()],
            'aiSettings' => app(AiSettings::class)->all(),
            'checkoutSettings' => app(CheckoutSettings::class)->all(),
            'invoiceCountryCoverage' => InvoiceVerification::countryCoverage(),
            'aiModels' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(AiSettings::MODELS), AiSettings::MODELS),
            'timezones' => GeneralOptions::timezones(),
            'messageLocales' => app(LocaleCatalog::class)->active()->map(fn ($locale): array => ['code' => $locale->code, 'name' => $locale->native_name, 'defaultMessage' => __('settings.closed_default', [], $locale->code)])->all(),
            'countries' => app(Countries::class)->options(),
            // Products that keep their own value instead of following the general setting.
            'overrides' => DB::table('products')->whereNull('deleted_at')->whereNotNull('out_of_stock_actions')->count(),
        ]);
    }

    /** @return list<array{id: string, name: string}> */
    private function categoryOptions(Request $request, GeneralSettings $settings): array
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'selected' => ['sometimes', 'array', 'max:100'], 'selected.*' => ['integer', 'distinct']]);
        $queries = app(CategoryQueries::class);
        $options = $queries->parents(null, $input['search'] ?? '', null);
        $ids = array_unique([...$settings->all()['bestsellers_excluded_categories'], ...($input['selected'] ?? [])]);
        $paths = $queries->paths(array_map(strval(...), $ids));
        foreach ($ids as $id) {
            if (isset($paths[$id])) {
                $options[] = ['id' => (string) $id, 'name' => implode(' / ', $paths[$id])];
            }
        }

        return collect($options)->unique('id')->values()->all();
    }

    public function update(Request $request, GeneralSettings $settings): RedirectResponse
    {
        DB::transaction(function () use ($request, $settings): void {
            $settings->save((string) $request->user()->getAuthIdentifier(), $request->only([...array_keys(GeneralOptions::DEFAULTS), 'bestsellers_excluded_categories', 'closed_messages', 'price_hidden_messages', 'brand_landing_pages_confirmed', 'version', 'out_of_stock_action', 'tax_calculation_method', 'display_catalog_prices_with_tax', 'display_cart_prices_with_tax', 'tax_estimate_without_address', 'tax_default_country', 'tax_default_region', ...GeneralSettings::ORDER_EMAIL_FIELDS, ...GeneralSettings::MAIL_DELIVERY_FIELDS, 'smtp_password', 'smtp_password_clear']));
            if ($request->has('checkout_settings')) {
                $checkout = $request->validate(['checkout_settings' => ['required', 'array:version,enabled,greek_provider,international_provider,country_codes,aade_username,aade_password,vatsense_key,clear_aade_username,clear_aade_password,clear_vatsense_key']]);
                try {
                    app(CheckoutSettings::class)->save((string) $request->user()->getAuthIdentifier(), $checkout['checkout_settings']);
                } catch (ValidationException $failure) {
                    throw ValidationException::withMessages(collect($failure->errors())->mapWithKeys(fn ($messages, $field): array => ['checkout_settings.'.$field => $messages])->all());
                }
            }
            if ($request->has('ai_settings')) {
                $aiInput = $request->validate(['ai_settings' => ['required', 'array:version,enabled,api_key,clear_key,model,max_tokens,hourly_limit,daily_limit']]);
                try {
                    app(AiSettings::class)->save((string) $request->user()->getAuthIdentifier(), $aiInput['ai_settings']);
                } catch (ValidationException $failure) {
                    throw ValidationException::withMessages(collect($failure->errors())->mapWithKeys(fn ($messages, $field): array => ['ai_settings.'.$field => $messages])->all());
                }
            }
        });

        return back()->with('status', __('settings.saved'));
    }
}
