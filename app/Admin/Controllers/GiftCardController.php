<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Marketing\Infrastructure\GiftCardMail;
use App\Marketing\Infrastructure\GiftCardQueries;
use App\Marketing\Infrastructure\GiftCards;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class GiftCardController
{
    public function index(Request $request, GiftCardQueries $queries): Response
    {
        Gate::authorize('giftcards.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:128'], 'status' => ['nullable', Rule::in(GiftCardRules::STATUSES)], 'sort' => ['nullable', Rule::in(GiftCardQueries::SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        $filters = ['sort' => 'created_at', 'direction' => 'desc', 'per_page' => '25', ...$filters];

        return Inertia::render('Admin/GiftCards/Index', [...$this->shell($request), 'giftCards' => $queries->listing($filters), 'filters' => $filters]);
    }

    public function create(Request $request, GiftCardQueries $queries): Response
    {
        Gate::authorize('giftcards.manage');

        return $this->form($request, $queries, null);
    }

    public function edit(Request $request, GiftCardQueries $queries, string $giftCard): Response
    {
        Gate::authorize('giftcards.view');

        return $this->form($request, $queries, $giftCard);
    }

    private function form(Request $request, GiftCardQueries $queries, ?string $id): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'between:1,1000000']]);
        $card = $id === null ? null : ($queries->editor($id) ?? abort(404));
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return Inertia::render('Admin/GiftCards/Form', [...$this->shell($request, $card === null ? __('giftcards.create') : $card['code']), 'giftCard' => $card,
            'currency' => ['code' => $card['currency_code'] ?? $currency->code, 'decimals' => $currency->decimals],
            'locales' => $queries->locales(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'history' => $id === null ? null : $queries->history($id), 'emails' => $id === null ? [] : $queries->emails($id),
        ]);
    }

    public function store(Request $request, GiftCards $cards): RedirectResponse
    {
        $id = $cards->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/gift-cards/'.$id.'/edit')->with('status', __('giftcards.saved'));
    }

    public function update(Request $request, GiftCards $cards, string $giftCard): RedirectResponse
    {
        $cards->save((string) $request->user()->id, $giftCard, $request->all());

        return redirect('/admin/gift-cards/'.$giftCard.'/edit')->with('status', __('giftcards.saved'));
    }

    /** Status change from the list; "used" cannot be chosen. */
    public function status(Request $request, GiftCards $cards, string $giftCard): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(GiftCardRules::MANUAL)], 'notify' => ['required', 'boolean']]);
        $cards->setStatus((string) $request->user()->id, $giftCard, (int) $data['version'], $data['status'], (bool) $data['notify']);

        return back()->with('status', __('giftcards.status_saved'));
    }

    public function send(Request $request, GiftCardMail $mail, string $giftCard): RedirectResponse
    {
        $mail->resend((string) $request->user()->id, $giftCard);

        return back()->with('status', __('giftcards.send_queued'));
    }

    public function destroy(Request $request, GiftCards $cards, string $giftCard): RedirectResponse
    {
        Gate::authorize('giftcards.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $cards->delete((string) $request->user()->id, [['id' => $giftCard, 'version' => (int) $data['version']]]);

        return redirect('/admin/gift-cards')->with('status', __('giftcards.deleted'));
    }

    public function bulkDestroy(Request $request, GiftCards $cards): RedirectResponse
    {
        Gate::authorize('giftcards.delete');
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'rows.*.version' => ['required', 'integer', 'min:1']]);
        $cards->delete((string) $request->user()->id, array_map(fn (array $r): array => ['id' => (string) $r['id'], 'version' => (int) $r['version']], $data['rows']));

        return redirect('/admin/gift-cards')->with('status', __('giftcards.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('giftcards.title'), 'href' => $current === null ? null : '/admin/gift-cards']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
