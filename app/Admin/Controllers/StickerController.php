<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\StickerQueries;
use App\Marketing\Infrastructure\Stickers;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StickerController
{
    public function index(Request $request, StickerQueries $queries): Response
    {
        Gate::authorize('stickers.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:128'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'type' => ['nullable', Rule::in(['manual', 'dynamic'])], 'style' => ['nullable', Rule::in(['text', 'image'])], 'sort' => ['nullable', Rule::in(['name', 'priority', 'updated_at', 'starts_at', 'ends_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);

        return Inertia::render('Admin/Stickers/Index', [...$this->shell($request), 'stickers' => $queries->listing($filters), 'filters' => (object) $filters, 'limits' => $queries->limits()]);
    }

    public function create(Request $request, StickerQueries $queries): Response
    {
        Gate::authorize('stickers.manage');

        return $this->form($request, $queries, null);
    }

    public function edit(Request $request, StickerQueries $queries, string $sticker): Response
    {
        Gate::authorize('stickers.view');

        return $this->form($request, $queries, $sticker);
    }

    private function form(Request $request, StickerQueries $queries, ?string $id): Response
    {
        $input = $request->validate(['feature_id' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', Rule::in(['products', 'categories', 'features', 'options', 'images'])], 'search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:500'], 'selected.*' => ['integer', 'min:1']]);
        $sticker = $id === null ? null : $queries->editor($id);
        $options = [];
        foreach (['products', 'categories', 'features', 'options', 'images'] as $kind) {
            $field = ['categories' => 'category_id', 'features' => 'feature_id', 'options' => 'option_id'][$kind] ?? null;
            $selected = $field === null ? ($kind === 'products' ? ($sticker['products'] ?? []) : [$sticker['image_media_id'] ?? '']) : array_column($sticker['conditions'] ?? [], $field);
            $selected = array_values(array_unique(array_filter([...$selected, ...(($input['kind'] ?? '') === $kind ? ($input['selected'] ?? []) : [])])));
            $search = ($input['kind'] ?? '') === $kind ? ($input['search'] ?? '') : '';
            if (in_array($kind, ['products', 'categories'], true)) {
                $options[$kind] = app(CouponQueries::class)->options($kind === 'products' ? 'product' : 'category', $search, $selected);
            } elseif ($kind === 'images') {
                $q = DB::table('stickers')->whereNotNull('image_media_id')->select('image_media_id as id', 'name');
                $chosen = (clone $q)->whereIn('image_media_id', $selected)->get();
                if ($search !== '') {
                    $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
                }
                $options[$kind] = $chosen->concat($q->orderBy('id')->limit(30)->get())->unique('id')->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();
            } else {
                $options[$kind] = $queries->choices($kind, $search, $selected, ($input['kind'] ?? '') === 'options' ? ($input['feature_id'] ?? null) : null);
            }
        }

        return Inertia::render('Admin/Stickers/Form', [...$this->shell($request), 'sticker' => $sticker, 'options' => $options, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'currency' => app(CurrencyRepository::class)->currentPrimary()->code, 'timezone' => app(GeneralSettings::class)->all()['timezone']]);
    }

    public function store(Request $request, Stickers $stickers): RedirectResponse
    {
        $id = $stickers->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/stickers/'.$id.'/edit')->with('status', __('stickers.saved'));
    }

    public function update(Request $request, Stickers $stickers, string $sticker): RedirectResponse
    {
        $stickers->save((string) $request->user()->id, $sticker, $request->all());

        return redirect('/admin/stickers/'.$sticker.'/edit')->with('status', __('stickers.saved'));
    }

    public function status(Request $request, Stickers $stickers, string $sticker): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $stickers->status((string) $request->user()->id, $sticker, (int) $data['version'], $data['status']);

        return back()->with('status', __('stickers.saved'));
    }

    public function destroy(Request $request, Stickers $stickers, string $sticker): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $stickers->delete((string) $request->user()->id, $sticker, (int) $data['version']);

        return redirect('/admin/stickers')->with('status', __('stickers.deleted'));
    }

    public function limits(Request $request, Stickers $stickers): RedirectResponse
    {
        $stickers->limits((string) $request->user()->id, $request->all());

        return back()->with('status', __('stickers.saved'));
    }

    public function image(string $sticker, PrivateImageStorage $images): StreamedResponse
    {
        Gate::authorize('stickers.view');
        $id = DB::table('stickers')->where('id', $sticker)->value('image_media_id');
        abort_if($id === null, 404);

        return $images->response(MediaFileRecord::findOrFail($id));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('stickers.title'), 'href' => '/admin/stickers']]];
    }
}
