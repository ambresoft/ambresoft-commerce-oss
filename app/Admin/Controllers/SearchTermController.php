<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Search\Infrastructure\SearchTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reports → Popular searches (`search.manage`): what shoppers search for, and which terms the Popular searches block may show. */
final class SearchTermController
{
    public function index(Request $request, SearchTerms $terms): Response
    {
        Gate::authorize('search.manage');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'visibility' => ['nullable', Rule::in(['shown', 'hidden'])], 'results' => ['nullable', Rule::in(['with', 'none'])],
            'sort' => ['nullable', Rule::in(SearchTerms::SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in(['25', '50', '100'])], 'page' => ['nullable', 'integer', 'min:1']]);
        // An empty PHP array serializes as a JS array, whose inherited .sort would mask the default.
        $filters = ['sort' => 'searches', 'direction' => 'desc', 'per_page' => '25', ...array_map(strval(...), array_filter($filters, fn ($value): bool => $value !== null))];

        return Inertia::render('Admin/SearchTerms/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('reports.title'), 'href' => null], ['label' => __('search.terms_title'), 'href' => null]],
            'terms' => $terms->listing($filters), 'filters' => $filters,
        ]);
    }

    public function visibility(Request $request, SearchTerms $terms, string $term): RedirectResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $terms->hide((string) $request->user()->id, $term, (bool) $data['hidden']);

        return back()->with('status', __($data['hidden'] ? 'search.term_hidden' : 'search.term_shown'));
    }
}
