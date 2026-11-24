<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveBrandData;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\DemoPicture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Opt-in local examples: recognize only unambiguous names, preserve existing assignments, and create no products/orders. */
final class BrandDemoSeeder
{
    public function run(string $actor): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Brand examples are only available in local/testing.');
        }
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('brands.manage');
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('products.update');

        return app(AuditLogger::class)->operation('brand_examples', $actor, fn () => DB::transaction(function () use ($actor): int {
            app(CategoryHierarchy::class)->lock();
            $locale = app(LocaleCatalog::class)->defaultCode();
            $rows = DB::table('products as p')->join('product_translations as t', 't.product_id', 'p.id')->join('locales as l', 'l.id', 't.locale_id')->where('l.code', $locale)->whereNull('p.deleted_at')->whereNull('p.brand_id')->orderBy('p.id')->get(['p.id', 't.name']);
            $known = [
                'apple' => ['Apple', '/^(?:apple\b|iphone\b|ipad\b|macbook\b)/i', '#e2e8f0'],
                'samsung' => ['Samsung', '/^(?:samsung\b|galaxy\b)/i', '#dbeafe'],
                'xiaomi' => ['Xiaomi', '/^xiaomi\b/i', '#ffedd5'],
                'google' => ['Google', '/^google\s+pixel\b/i', '#d1fae5'],
                'dell' => ['Dell', '/^dell\b/i', '#dbeafe'],
                'asus' => ['ASUS', '/^asus\b/i', '#fee2e2'],
                'lenovo' => ['Lenovo', '/^lenovo\b/i', '#fee2e2'],
                'lg' => ['LG', '/^lg\b/i', '#fce7f3'],
                'aster' => ['Aster', '/^aster\b/i', '#d1fae5'],
                'nimbus' => ['Nimbus', '/^nimbus\b/i', '#dbeafe'],
                'trailcraft' => ['Trailcraft', '/^trailcraft\b/i', '#fef3c7'],
                'lumo' => ['Lumo', '/^lumo\b/i', '#ede9fe'],
                'volta' => ['Volta', '/^volta\b/i', '#fee2e2'],
                'orbit' => ['Orbit', '/^orbit\b/i', '#e0f2fe'],
                'kova' => ['Kova', '/^kova\b/i', '#f1f5f9'],
            ];
            $assigned = 0;
            foreach ($known as $code => [$name,$pattern,$color]) {
                $ids = $rows->filter(fn ($p) => preg_match($pattern, $p->name) === 1)->pluck('id')->all();
                if ($ids === []) {
                    continue;
                }
                $id = DB::table('brands')->where('code', $code)->value('id');
                if ($id === null) {
                    $translations = [];
                    foreach (app(LocaleCatalog::class)->active() as $language) {
                        $translations[$language->code] = ['name' => $name, 'description' => '<p>'.($language->code === 'el' ? 'Προϊόντα ' : 'Products by ').$name.'.</p>', 'image_alt' => $name];
                    }
                    $id = app(Brands::class)->save($actor, null, new SaveBrandData(['code' => $code, 'status' => 'active', 'position' => 0, 'translations' => $translations]));
                    // Placeholder picture through the existing sample-image pipeline; replace it with a real logo in Brands.
                    DB::table('brands')->where('id', $id)->update(['image_media_id' => app(DemoPicture::class)->create('#ffffff', $color, 240, 120)]);
                }
                $assigned += DB::table('products')->whereIn('id', $ids)->whereNull('brand_id')->update(['brand_id' => $id, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }

            return $assigned;
        }));
    }
}
