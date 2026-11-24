<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Domain\Service\FeatureRules;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Catalog\Infrastructure\FeatureImpactRequired;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FeatureController
{
    public function index(Request $request, FeatureQueries $queries): Response
    {
        Gate::authorize('features.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(FeatureRules::TYPES)], 'group_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'], 'required' => ['nullable', Rule::in(['0', '1'])], 'filterable' => ['nullable', Rule::in(['0', '1'])], 'status' => ['nullable', Rule::in(['active', 'archived', 'incomplete'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([25, 50, 100])]]);

        return Inertia::render('Admin/Features/Index', [...$this->shell($request), 'features' => $queries->listing($filters), 'filters' => (object) $filters, 'groups' => $queries->groups(), 'categories' => $this->categories($request), 'remediationCount' => $queries->remediationCount()]);
    }

    public function edit(Request $request, FeatureQueries $queries, ?string $feature = null): Response
    {
        Gate::authorize($feature === null ? 'features.create' : 'features.update');
        $definition = $feature === null ? null : ($queries->definitions([$feature])[0] ?? abort(404));

        if ($feature !== null && ! $request->header('X-Inertia-Partial-Data')) {
            app(AuditLogger::class)->record(new AuditEvent('feature.opened', 'feature', $feature, entityLabel: $definition['name']));
        }

        return Inertia::render('Admin/Features/Form', [...$this->shell($request), 'feature' => $definition, 'brandLandingPages' => app(GeneralSettings::class)->all()['brand_landing_pages'], 'groups' => $queries->groups(), 'categories' => $this->categories($request, $definition['categories'] ?? []), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'impactPreview' => $request->session()->get('feature_impact')]);
    }

    public function save(Request $request, FeatureAdministration $features, ?string $feature = null): RedirectResponse
    {
        return $this->mutation(function () use ($request, $features, $feature): string {
            $id = $features->save((string) $request->user()->id, $feature, new SaveFeatureData($request->input()));

            return '/admin/features/'.$id.'/edit';
        });
    }

    public function action(Request $request, FeatureAdministration $features, string $feature): RedirectResponse
    {
        return $this->mutation(function () use ($request, $features, $feature): string {
            $features->action((string) $request->user()->id, $feature, new SaveFeatureData($request->input()));

            return $request->input('action') === 'delete' ? '/admin/features' : '/admin/features/'.$feature.'/edit';
        });
    }

    public function option(Request $request, FeatureAdministration $features, string $feature, ?string $option = null): RedirectResponse
    {
        Gate::authorize('features.update');
        $rules = ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', Rule::dimensions()->maxWidth(6000)->maxHeight(6000)];
        $request->validate(['image' => $rules, 'swatch_image' => $rules]);
        $image = $request->file('image');
        $swatch = $request->file('swatch_image');

        return $this->mutation(function () use ($request, $features, $feature, $option, $image, $swatch): string {
            $features->option((string) $request->user()->id, $feature, $option, new SaveFeatureData($request->except(['image', 'swatch_image']), $image === null ? null : new ImageUpload($image->getPathname()), $swatch === null ? null : new ImageUpload($swatch->getPathname())));

            return '/admin/features/'.$feature.'/edit';
        });
    }

    /**
     * Saves every edited and new option of the table in one atomic operation (labels only; lifecycle actions and
     * landing/swatch details keep their own per-option requests). Option versions guard each edit; the feature version
     * is re-read between saves because every option save advances it.
     */
    public function options(Request $request, FeatureAdministration $features, string $feature): RedirectResponse
    {
        Gate::authorize('features.update');
        $data = $request->validate([
            'feature_version' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array', 'min:1', 'max:200'],
            'changes.*.id' => ['nullable', 'integer', 'min:1'],
            'changes.*.version' => ['nullable', 'integer', 'min:1'],
            'changes.*.translations' => ['required', 'array'],
        ]);

        return $this->mutation(function () use ($request, $features, $feature, $data): string {
            DB::transaction(function () use ($request, $features, $feature, $data): void {
                $version = (int) $data['feature_version'];
                foreach ($data['changes'] as $index => $change) {
                    $fields = ['action' => 'save', 'feature_version' => $version, 'translations' => $change['translations']];
                    if (isset($change['id'])) {
                        $fields['version'] = $change['version'] ?? 0;
                    }
                    try {
                        $features->option((string) $request->user()->id, $feature, isset($change['id']) ? (string) $change['id'] : null, new SaveFeatureData($fields));
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => ['changes.'.$index.'.'.$key => $messages[0]])->all());
                    }
                    $version = (int) DB::table('features')->where('id', $feature)->value('lock_version');
                }
            });

            return '/admin/features/'.$feature.'/edit?tab=options';
        });
    }

    public function optionImage(string $feature, string $option, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['features.view', 'features.update']), 403);
        $media = DB::table('feature_options')->where('id', $option)->where('feature_id', $feature)->value('landing_image_media_id');
        abort_if($media === null, 404);

        return $images->response(MediaFileRecord::findOrFail($media));
    }

    public function optionSwatch(string $feature, string $option, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['features.view', 'features.update']), 403);
        $media = DB::table('feature_options')->where('id', $option)->where('feature_id', $feature)->value('swatch_image_media_id');
        abort_if($media === null, 404);

        return $images->response(MediaFileRecord::findOrFail($media));
    }

    public function groups(Request $request, FeatureQueries $queries): Response
    {
        Gate::authorize('features.groups');

        return Inertia::render('Admin/Features/Groups', [...$this->shell($request), 'groups' => $queries->groups(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'impactPreview' => $request->session()->get('feature_impact')]);
    }

    public function group(Request $request, FeatureAdministration $features, ?string $group = null): RedirectResponse
    {
        return $this->mutation(function () use ($request, $features, $group): string {
            $features->group((string) $request->user()->id, $group, new SaveFeatureData($request->input()));

            return '/admin/features/groups';
        });
    }

    public function ordering(Request $request, FeatureQueries $queries): Response
    {
        Gate::authorize('features.order');

        return Inertia::render('Admin/Features/Ordering', [...$this->shell($request), 'groups' => $queries->groups(), 'features' => $queries->ordering(), 'catalogVersion' => $queries->revision()]);
    }

    public function reorder(Request $request, FeatureAdministration $features): RedirectResponse
    {
        return $this->mutation(function () use ($request, $features): string {
            $features->reorder((string) $request->user()->id, new SaveFeatureData($request->input()));

            return '/admin/features/ordering';
        });
    }

    public function remediation(Request $request, FeatureQueries $queries): Response
    {
        Gate::authorize('features.view');
        Gate::authorize('products.view');
        $v = $request->validate(['feature_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);

        return Inertia::render('Admin/Features/Remediation', [...$this->shell($request), 'products' => $queries->remediation(isset($v['feature_id']) ? (string) $v['feature_id'] : null)]);
    }

    /** @param callable(): string $operation */
    private function mutation(callable $operation): RedirectResponse
    {
        try {
            return redirect($operation())->with('status', __('features.saved'));
        } catch (FeatureImpactRequired $e) {
            return back()->with('feature_impact', ['impact' => $e->impact, 'token' => $e->token])->withErrors(['confirmation' => __('features.confirm_impact')]);
        }
    }

    /** @param list<string> $selected
     * @return list<array{id: string, name: string}>
     */
    private function categories(Request $request, array $selected = []): array
    {
        $v = $request->validate(['category_search' => ['nullable', 'string', 'max:100']]);
        $q = DB::table('categories')->whereNull('system_key');
        if (! empty($v['category_search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $v['category_search']).'%';
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('category_translations as ct')->whereColumn('ct.category_id', 'categories.id')->where('ct.name', 'ilike', $term));
        }
        $ids = array_values(array_unique([...$q->orderBy('id')->limit(100)->pluck('id')->map(strval(...))->all(), ...$selected]));
        $paths = app(CategoryQueries::class)->paths($ids);

        return array_map(fn ($id): array => ['id' => $id, 'name' => implode(' → ', $paths[$id] ?? [])], $ids);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('features.title'), 'href' => '/admin/features']]];
    }
}
