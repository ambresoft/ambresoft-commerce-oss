<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Admin\Requests\SaveCategoryRequest;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\DeleteCategoryTarget;
use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Customer\Infrastructure\CustomerQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Website\Infrastructure\BlogLinks;
use App\Website\Infrastructure\FaqQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CategoryController
{
    public function index(Request $request, AdminNavigation $navigation, CategoryQueries $queries): Response
    {
        Gate::authorize('categories.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'parent' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $filtered = ($filters['search'] ?? '') !== '' || ($filters['status'] ?? '') !== '';

        return Inertia::render('Admin/Categories/Index', [
            ...$this->shell($request, $navigation), 'filters' => $filters, 'filtered' => $filtered,
            'branch' => fn () => $queries->branch(isset($filters['parent']) ? (string) $filters['parent'] : null, (int) ($filters['page'] ?? 1)),
            'results' => fn () => $filtered ? $queries->search($filters['search'] ?? '', $filters['status'] ?? null, (int) ($filters['page'] ?? 1)) : null,
        ]);
    }

    public function create(Request $request, AdminNavigation $navigation, CategoryQueries $queries): Response
    {
        Gate::authorize('categories.create');

        return $this->form($request, $navigation, $queries, null);
    }

    public function edit(Request $request, AdminNavigation $navigation, CategoryQueries $queries, string $category): Response
    {
        Gate::authorize('categories.update');
        $record = CategoryRecord::findOrFail($category);
        abort_if($record->system_key !== null, 403);

        return $this->form($request, $navigation, $queries, $record);
    }

    private function form(Request $request, AdminNavigation $navigation, CategoryQueries $queries, ?CategoryRecord $record): Response
    {
        $filters = $request->validate(['parent' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('system_key')], 'parent_search' => ['nullable', 'string', 'max:100'], 'selected_parent' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('system_key')]]);
        $parent = $record !== null ? $record->parent_id : ($filters['parent'] ?? null);
        $selected = array_key_exists('selected_parent', $filters) ? $filters['selected_parent'] : $parent;
        $selected = $selected === null ? null : (string) $selected;

        $editor = $record === null ? null : $queries->editor($record);
        if ($record !== null && ! $request->header('X-Inertia-Partial-Data')) {
            app(AuditLogger::class)->record(new AuditEvent('category.opened', 'category', (string) $record->id, entityLabel: $editor['displayName']));
        }

        return Inertia::render('Admin/Categories/Form', [
            ...$this->shell($request, $navigation), 'category' => $editor,
            'initialParentId' => $parent === null ? null : (string) $parent,
            'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'parentOptions' => fn () => $queries->parents($record === null ? null : (string) $record->id, $filters['parent_search'] ?? '', $selected),
            'parentAgeRestriction' => fn () => $queries->parentAgeRestriction($selected),
            'faqOptions' => fn () => Gate::allows('faqs.view') ? app(FaqQueries::class)->options('category', $editor['faqIds'] ?? []) : [],
            'blogPostOptions' => fn () => Gate::allows('blog.view') ? app(BlogLinks::class)->options() : [],
            'customerGroups' => fn () => app(CustomerQueries::class)->groupOptions($editor['customerGroups'] ?? []),
        ]);
    }

    public function store(SaveCategoryRequest $request, ManageCategoriesHandler $handler): RedirectResponse
    {
        return $this->save($request, $handler, null);
    }

    public function update(SaveCategoryRequest $request, ManageCategoriesHandler $handler, string $category): RedirectResponse
    {
        return $this->save($request, $handler, $category);
    }

    private function save(SaveCategoryRequest $request, ManageCategoriesHandler $handler, ?string $category): RedirectResponse
    {
        try {
            $saved = $handler->save((string) $request->user()->getAuthIdentifier(), $category, $request->toData());
        } catch (InvalidCategoryChange $failure) {
            throw ValidationException::withMessages(['category' => __($failure->getMessage())]);
        }
        // Staff stay in the editor, on the tab they saved from.
        parse_str((string) parse_url((string) $request->headers->get('referer'), PHP_URL_QUERY), $query);
        $tab = is_string($query['tab'] ?? null) && preg_match('/^[a-z]{1,20}$/D', $query['tab']) === 1 ? '?tab='.$query['tab'] : '';

        return redirect('/admin/categories/'.$saved.'/edit'.$tab)->with('status', __('categories.saved'));
    }

    public function destroy(Request $request, ManageCategoriesHandler $handler, string $category): RedirectResponse
    {
        Gate::authorize('categories.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            $handler->delete((string) $request->user()->getAuthIdentifier(), $category, (int) $data['version']);
        } catch (InvalidCategoryChange $failure) {
            throw ValidationException::withMessages(['category' => __($failure->getMessage())]);
        }

        return to_route(Gate::allows('categories.view') ? 'admin.categories.index' : 'admin.dashboard')->with('status', __('categories.deleted'));
    }

    public function bulkDestroy(Request $request, ManageCategoriesHandler $handler): RedirectResponse
    {
        Gate::authorize('categories.delete');
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:40'],
            'targets.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'targets.*.version' => ['required', 'integer', 'min:1'],
        ]);
        $targets = array_map(fn (array $target): DeleteCategoryTarget => new DeleteCategoryTarget((string) $target['id'], (int) $target['version']), $data['targets']);
        try {
            $handler->deleteMany((string) $request->user()->getAuthIdentifier(), $targets);
        } catch (InvalidCategoryChange $failure) {
            throw ValidationException::withMessages(['targets' => __($failure->getMessage())]);
        }

        return to_route(Gate::allows('categories.view') ? 'admin.categories.index' : 'admin.dashboard')->with('status', __('categories.bulk_deleted', ['count' => count($targets)]));
    }

    public function image(string $category, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['categories.view', 'categories.update']), 403);
        $record = CategoryRecord::with('image')->findOrFail($category);
        abort_if($record->image === null, 404);

        return $images->response($record->image);
    }

    public function blockImage(string $category, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['categories.view', 'categories.update']), 403);
        $record = CategoryRecord::with('blockImage')->findOrFail($category);
        abort_if($record->blockImage === null, 404);

        return $images->response($record->blockImage);
    }

    public function menuIcon(string $category, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['categories.view', 'categories.update']), 403);
        $record = CategoryRecord::with('menuIcon')->findOrFail($category);
        abort_if($record->menuIcon === null, 404);

        return $images->response($record->menuIcon);
    }

    public function bannerImage(string $category, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['categories.view', 'categories.update']), 403);
        $record = CategoryRecord::with('bannerImage')->findOrFail($category);
        abort_if($record->bannerImage === null, 404);

        return $images->response($record->bannerImage);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, AdminNavigation $navigation): array
    {
        return ['navigation' => $navigation->forUser($request->user()), 'breadcrumbs' => [
            ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
            ['label' => __('categories.title'), 'href' => Gate::allows('categories.view') ? route('admin.categories.index', [], false) : null],
        ]];
    }
}
