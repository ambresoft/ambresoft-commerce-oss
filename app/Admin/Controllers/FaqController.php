<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Application\Command\SaveFaqData;
use App\Website\Domain\Service\FaqRules;
use App\Website\Infrastructure\FaqAdministration;
use App\Website\Infrastructure\FaqQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class FaqController
{
    public function index(Request $request, FaqQueries $queries): Response
    {
        Gate::authorize('faqs.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(FaqRules::TYPES)], 'status' => ['nullable', Rule::in(FaqRules::STATUSES)], 'page' => ['nullable', 'integer', 'between:1,1000000']]);

        return Inertia::render('Admin/Faqs/Index', [...$this->shell($request), 'faqs' => $queries->listing($filters), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('faqs.manage');

        return Inertia::render('Admin/Faqs/Form', [...$this->shell($request), 'faq' => null]);
    }

    public function edit(Request $request, FaqQueries $queries, string $faq): Response
    {
        Gate::authorize('faqs.view');

        return Inertia::render('Admin/Faqs/Form', [...$this->shell($request), 'faq' => $queries->editor($faq) ?? abort(404)]);
    }

    public function store(Request $request, FaqAdministration $faqs): RedirectResponse
    {
        Gate::authorize('faqs.manage');
        $id = $faqs->save((string) $request->user()->id, null, $this->data($request));

        return redirect('/admin/faqs/'.$id.'/edit')->with('status', __('faqs.saved'));
    }

    public function update(Request $request, FaqAdministration $faqs, string $faq): RedirectResponse
    {
        Gate::authorize('faqs.manage');
        $faqs->save((string) $request->user()->id, $faq, $this->data($request));

        return back()->with('status', __('faqs.saved'));
    }

    public function destroy(Request $request, FaqAdministration $faqs, string $faq): RedirectResponse
    {
        Gate::authorize('faqs.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $faqs->delete((string) $request->user()->id, $faq, (int) $data['version']);

        return redirect('/admin/faqs')->with('status', __('faqs.deleted'));
    }

    public function options(Request $request, FaqQueries $queries): JsonResponse
    {
        Gate::authorize('faqs.view');
        $data = $request->validate(['owner' => ['required', Rule::in(['category', 'product', 'page'])], 'search' => ['nullable', 'string', 'max:100'], 'selected' => ['sometimes', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1', 'distinct']]);

        return response()->json($queries->options($data['owner'], array_map(strval(...), $data['selected'] ?? []), $data['search'] ?? ''));
    }

    private function data(Request $request): SaveFaqData
    {
        $data = $request->validate(['version' => [$request->route('faq') === null ? 'nullable' : 'required', 'integer', 'min:1'], 'type' => ['required', Rule::in(FaqRules::TYPES)], 'status' => ['required', Rule::in(FaqRules::STATUSES)], 'position' => ['required', 'integer', 'between:0,2147483647'], 'translations' => ['required', 'array']]);

        return new SaveFaqData($data['type'], $data['status'], (int) $data['position'], $data['translations'], isset($data['version']) ? (int) $data['version'] : null);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('faqs.title'), 'href' => '/admin/faqs']], 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()];
    }
}
