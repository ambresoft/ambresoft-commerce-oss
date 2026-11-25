<?php

namespace App\Catalog\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductPackages
{
    /** @return list<string> */
    public function items(string $id): array
    {
        return DB::table('product_package_items')->where('package_id', $id)->orderBy('position')->pluck('product_id')->map(strval(...))->all();
    }

    public function assigned(string $product): ?string
    {
        $id = DB::table('product_package_assignments')->where('product_id', $product)->value('package_id');

        return $id === null ? null : (string) $id;
    }

    /** @return list<array{id:string,name:string}> */
    public function options(?string $id = null): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', app()->getLocale())->id ?? $default;

        return DB::table('product_packages as p')->when($id !== null, fn ($q) => $q->where('p.id', $id))->leftJoin('product_package_translations as d', fn ($j) => $j->on('d.package_id', '=', 'p.id')->where('d.locale_id', $default))
            ->leftJoin('product_package_translations as c', fn ($j) => $j->on('c.package_id', '=', 'p.id')->where('c.locale_id', $current))
            ->orderBy('p.id')->get(['p.id', DB::raw("coalesce(c.name, d.name, '#' || p.id) as name")])->map(fn ($p): array => ['id' => (string) $p->id, 'name' => $p->name])->all();
    }

    /** @return array<string,mixed> */
    public function editor(string $id, int $page = 1): array
    {
        $p = DB::table('product_packages')->where('id', $id)->first() ?? abort(404);
        $translations = DB::table('product_package_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('package_id', $id)->pluck('t.name', 'l.code')->all();

        return ['id' => $id, 'version' => (int) $p->lock_version, 'translations' => (object) $translations, 'items' => $this->items($id),
            'assigned_page' => $page, 'assigned' => app(ProductCompanions::class)->describe(DB::table('product_package_assignments')->where('package_id', $id)->orderBy('product_id')->offset(($page - 1) * 100)->limit(100)->pluck('product_id')->map(strval(...))->all()),
            'assigned_count' => DB::table('product_package_assignments')->where('package_id', $id)->count()];
    }

    private function authorize(string $actor): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('products.update');
        app(CategoryHierarchy::class)->lock();
    }

    private function version(string $id, int $version): void
    {
        $p = DB::table('product_packages')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        if ((int) $p->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('variations.stale')]);
        }
    }

    /** @param array<string,mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        return DB::transaction(function () use ($actor, $id, $input): string {
            $this->authorize($actor);
            $locales = app(LocaleCatalog::class)->active();
            $rules = ['version' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')], 'translations' => ['required', 'array:'.implode(',', $locales->pluck('code')->all())]];
            foreach ($locales as $locale) {
                $rules['translations.'.$locale->code] = ['required', 'string', 'max:200', 'regex:/\S/u', 'not_regex:/[\x00-\x1f\x7f]/'];
            }
            $v = Validator::make($input, $rules)->validate();
            if ($id === null) {
                $id = (string) DB::table('product_packages')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            } else {
                $this->version($id, (int) $v['version']);
                DB::table('product_packages')->where('id', $id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            foreach ($locales as $locale) {
                DB::table('product_package_translations')->updateOrInsert(['package_id' => $id, 'locale_id' => $locale->id], ['name' => trim($v['translations'][$locale->code])]);
            }
            DB::table('product_package_items')->where('package_id', $id)->delete();
            foreach ($v['items'] as $position => $product) {
                DB::table('product_package_items')->insert(['package_id' => $id, 'product_id' => $product, 'position' => $position]);
            }

            return $id;
        });
    }

    public function delete(string $actor, string $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            $this->version($id, $version);
            DB::table('product_packages')->where('id', $id)->delete();
        });
    }

    /** @param list<string> $products */
    public function assign(string $actor, ?string $id, array $products, ?string $category = null): void
    {
        DB::transaction(function () use ($actor, $id, $products, $category): void {
            $this->authorize($actor);
            Validator::make(['package' => $id, 'products' => $products, 'category' => $category], ['package' => ['nullable', Rule::exists('product_packages', 'id')], 'products' => ['array', 'max:100'], 'products.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')], 'category' => ['nullable', Rule::exists('categories', 'id')]])->validate();
            $targets = DB::table('products')->whereNull('deleted_at')->where(fn ($q) => $q->whereIn('id', $products)->when($category !== null, fn ($q) => $q->orWhere('category_id', $category)))->orderBy('id')->pluck('id');
            foreach ($targets as $product) {
                if ($id === null) {
                    DB::table('product_package_assignments')->where('product_id', $product)->delete();
                } else {
                    DB::table('product_package_assignments')->updateOrInsert(['product_id' => $product], ['package_id' => $id]);
                }
            }
        });
    }
}
