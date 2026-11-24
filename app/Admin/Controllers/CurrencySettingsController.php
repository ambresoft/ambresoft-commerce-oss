<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Domain\Exception\InvalidCurrencyChange;
use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Model\Money;
use App\Pricing\Domain\Service\CurrencyFormatter;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Pricing\Infrastructure\CurrencyInput;
use App\Pricing\Infrastructure\Persistence\CurrencyMapper;
use App\Pricing\Infrastructure\Persistence\CurrencyRecord;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CurrencySettingsController
{
    private const SAMPLE = '1234.5';

    public function __construct(private CurrencyMapper $mapper, private CurrencyFormatter $formatter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('currencies.view');
        $currencies = $this->currencies();
        $primary = $this->primaryOf($currencies);

        return Inertia::render('Admin/Settings/Currencies', [...$this->shell($request), 'primary' => ['code' => $primary->code, 'name' => $primary->name], 'currencies' => array_map(fn (Currency $c): array => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol, 'rate' => $c->rate, 'primary' => $c->primary, 'active' => $c->active,
            'sample' => $this->formatter->format(new Money(self::SAMPLE, $c)),
        ], $currencies)]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('currencies.create');
        $primary = $this->primaryOf($this->currencies());

        return Inertia::render('Admin/Settings/CurrencyForm', [...$this->shell($request, __('currencies.create')), 'primary' => ['code' => $primary->code, 'name' => $primary->name], 'currency' => null, 'primaryChange' => null]);
    }

    public function store(Request $request, CurrencyInput $input, CurrencyAdministration $currencies): RedirectResponse
    {
        Gate::authorize('currencies.create');
        $id = $currencies->create((string) $request->user()->id, $input->data($request->all(), true));

        return redirect('/admin/settings/currencies/'.$id.'/edit')->with('status', __('currencies.saved'));
    }

    public function edit(Request $request, string $currency, PrimaryCurrencyChange $change): Response
    {
        Gate::authorize('currencies.view');
        $currencies = $this->currencies();
        $primary = $this->primaryOf($currencies);
        $target = collect($currencies)->firstWhere('id', $currency) ?? abort(404);

        return Inertia::render('Admin/Settings/CurrencyForm', [...$this->shell($request, $target->code), 'primary' => ['code' => $primary->code, 'name' => $primary->name], 'currency' => [
            'id' => $target->id, 'version' => $target->version, 'code' => $target->code, 'name' => $target->name, 'symbol' => $target->symbol, 'symbol_position' => $target->symbolPosition,
            'thousands_separator' => array_search($target->thousandsSeparator, CurrencyInput::THOUSANDS, true), 'decimal_separator' => array_search($target->decimalSeparator, CurrencyInput::DECIMAL, true),
            'decimals' => $target->decimals, 'rate' => $target->rate, 'status' => $target->active ? 'active' : 'disabled', 'primary' => $target->primary,
        ], 'primaryChange' => $target->primary ? null : $this->primaryChange($currencies, $primary, $target, $change)]);
    }

    public function update(Request $request, CurrencyInput $input, CurrencyAdministration $currencies, string $currency): RedirectResponse
    {
        // Field-specific capabilities (update/rates/status) are enforced by the Application operation.
        Gate::authorize('currencies.view');
        $record = CurrencyRecord::findOrFail($currency);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $currencies->update((string) $request->user()->id, $currency, (int) $version, $input->data($request->except('version'), false, $record->code));

        return redirect('/admin/settings/currencies/'.$currency.'/edit')->with('status', __('currencies.saved'));
    }

    public function primary(Request $request, CurrencyAdministration $currencies, string $currency): RedirectResponse
    {
        Gate::authorize('currencies.primary');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmation' => ['required', 'string', 'max:3']], ['confirmation.*' => __('currencies.confirm_code', ['code' => CurrencyRecord::findOrFail($currency)->code])]);
        $result = $currencies->makePrimary((string) $request->user()->id, $currency, (int) $data['version'], $data['confirmation']);

        return redirect('/admin/settings/currencies')->with('status', __('currencies.primary_changed', ['count' => $result['products']]));
    }

    public function destroy(Request $request, CurrencyAdministration $currencies, string $currency): RedirectResponse
    {
        Gate::authorize('currencies.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $currencies->delete((string) $request->user()->id, $currency, (int) $data['version']);

        return redirect('/admin/settings/currencies')->with('status', __('currencies.deleted'));
    }

    /**
     * Authoritative settings view: read storage directly rather than the display cache.
     *
     * @return list<Currency>
     */
    private function currencies(): array
    {
        return CurrencyRecord::orderByDesc('is_primary')->orderBy('code')->get()->map(fn (CurrencyRecord $r): Currency => $this->mapper->toDomain($this->mapper->row($r)))->values()->all();
    }

    /** @param list<Currency> $currencies */
    private function primaryOf(array $currencies): Currency
    {
        return collect($currencies)->firstWhere('primary', true);
    }

    /**
     * Consequences shown before confirmation; the operation recomputes everything under its lock.
     *
     * @param  list<Currency>  $currencies
     * @return array<string, mixed>
     */
    private function primaryChange(array $currencies, Currency $primary, Currency $target, PrimaryCurrencyChange $change): array
    {
        $preview = ['products' => DB::table('products')->whereNotNull('price')->count(), 'from' => $this->formatter->format(new Money('100', $primary)), 'to' => null, 'rates' => [], 'error' => null];
        try {
            $rates = $change->rebasedRates($currencies, $target);
            $max = DB::table('products')->max('price');
            if ($max !== null) {
                $change->convertPrice(Decimal::trim((string) $max), $target);
            }
            $preview['to'] = $this->formatter->format(new Money($change->convertPrice('100', $target), $target));
            foreach ($currencies as $currency) {
                $preview['rates'][] = ['code' => $currency->code, 'from' => $currency->rate, 'to' => $rates[$currency->code]];
            }
        } catch (InvalidCurrencyChange $e) {
            $preview['error'] = __($e->getMessage(), $e->parameters);
        }

        return $preview;
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('currencies.title'), 'href' => '/admin/settings/currencies']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
