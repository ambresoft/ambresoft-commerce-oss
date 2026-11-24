<?php

namespace Ambresoft\ProductFeeds\Infrastructure;

use Ambresoft\ProductFeeds\Application\Service\FeedXml;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Application\Command\CatalogCategory;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Contract\CatalogUnavailable;
use App\Storefront\Application\Contract\StorefrontCatalog;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Feed settings, generation and the stored files. Products are read only through the public storefront catalog. */
final class ProductFeeds
{
    private const TABLE = 'ambresoft_product_feeds';

    private const FLAGS = ['title_for_empty_description', 'apply_promotions', 'use_list_price', 'discount_label', 'show_categories',
        'additional_images', 'in_stock_only', 'show_weight'];

    public function __construct(private StorefrontCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return DB::table(self::TABLE)->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'channel', 'status', 'product_count', 'generated_at', 'last_error'])
            ->map(fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string, mixed>|null Settings as the form edits them, the cron password decrypted */
    public function find(string $id): ?array
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $feed = (array) $row;
        foreach (self::FLAGS as $flag) {
            $feed[$flag] = (bool) $feed[$flag];
        }
        $feed['cron_password'] = Crypt::decryptString($row->cron_password);
        $feed['attributes'] = json_decode((string) $row->attributes, true, 512, JSON_THROW_ON_ERROR);
        $feed['categories'] = DB::table('ambresoft_product_feed_categories')->where('feed_id', $id)->orderBy('category_id')->pluck('category_id')->map(strval(...))->all();
        $feed['min_price'] = $row->min_price === null ? null : Decimal::trim((string) $row->min_price);

        return $feed;
    }

    /** @return array<string, mixed> Defaults for a new feed, matching the CS-Cart add-on's */
    public function blank(): array
    {
        return ['id' => null, 'name' => '', 'channel' => 'google', 'cron_password' => Str::random(24), 'status' => 'active', 'scope' => 'all',
            'categories' => [], 'locale_id' => null, 'id_source' => 'id', 'description_source' => 'full', 'description_limit' => null,
            'title_for_empty_description' => true, 'empty_description' => '', 'apply_promotions' => true, 'use_list_price' => false,
            'discount_label' => false, 'show_categories' => true, 'additional_images' => false, 'in_stock_only' => false, 'min_price' => null,
            'show_weight' => false, 'attributes' => [
                ['attribute' => 'brand', 'feature' => 'Brand', 'fallback' => ''],
                ['attribute' => 'condition', 'feature' => 'Condition', 'fallback' => 'new'],
            ]];
    }

    /** @param array<string, mixed> $input */
    public function save(?string $id, array $input): string
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'channel' => ['required', Rule::in(FeedXml::CHANNELS)],
            'cron_password' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'scope' => ['required', Rule::in(['all', 'categories'])],
            'categories' => ['array', 'max:500', 'required_if:scope,categories'],
            'categories.*' => ['string', 'distinct', Rule::in(array_map(fn ($c): string => $c->id, $this->categories()))],
            'locale_id' => ['nullable', 'integer', Rule::in(app(LocaleCatalog::class)->active()->pluck('id')->all())],
            'id_source' => ['required', Rule::in(['id', 'sku'])],
            'description_source' => ['required', Rule::in(['full', 'short'])],
            'description_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'empty_description' => ['nullable', 'string', 'max:5000'],
            'min_price' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'attributes' => ['array', 'max:'.count(FeedXml::ATTRIBUTES)],
            'attributes.*.attribute' => ['required', 'distinct', Rule::in(FeedXml::ATTRIBUTES)],
            'attributes.*.feature' => ['nullable', 'string', 'max:120'],
            'attributes.*.fallback' => ['nullable', 'string', 'max:200', 'required_without:attributes.*.feature'],
            ...array_fill_keys(self::FLAGS, ['required', 'boolean']),
        ], [], ['cron_password' => __('cms-product-feeds::ui.cron_password')])->validate();

        $values = ['name' => trim($data['name']), 'channel' => $data['channel'], 'cron_password' => Crypt::encryptString($data['cron_password']),
            'status' => $data['status'], 'scope' => $data['scope'], 'locale_id' => $data['locale_id'] ?? null, 'id_source' => $data['id_source'],
            'description_source' => $data['description_source'], 'description_limit' => $data['description_limit'] ?? null,
            'empty_description' => trim((string) ($data['empty_description'] ?? '')), 'min_price' => $data['min_price'] ?? null,
            'attributes' => json_encode(array_map(fn (array $a): array => ['attribute' => $a['attribute'], 'feature' => trim((string) ($a['feature'] ?? '')),
                'fallback' => trim((string) ($a['fallback'] ?? ''))], array_values($data['attributes'] ?? [])), JSON_THROW_ON_ERROR),
            'updated_at' => now()];
        foreach (self::FLAGS as $flag) {
            $values[$flag] = (bool) $data[$flag];
        }

        return DB::transaction(function () use ($id, $values, $data): string {
            if ($id === null) {
                $id = (string) Str::uuid();
                DB::table(self::TABLE)->insert([...$values, 'id' => $id, 'created_at' => now()]);
            } elseif (DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->update($values) === 0) {
                abort(404);
            }
            DB::table('ambresoft_product_feed_categories')->where('feed_id', $id)->delete();
            DB::table('ambresoft_product_feed_categories')->insert(array_map(fn (string $c): array => ['feed_id' => $id, 'category_id' => (int) $c],
                $data['scope'] === 'categories' ? array_values($data['categories']) : []));

            return $id;
        });
    }

    public function delete(string $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->delete();
    }

    /** Whether the feed is active and the password matches; disabled and unknown feeds never match. */
    public function cronAllowed(string $id, string $password): bool
    {
        $stored = DB::table(self::TABLE)->where('id', $id)->where('status', 'active')->value('cron_password');

        return $stored !== null && $password !== '' && hash_equals(Crypt::decryptString($stored), $password);
    }

    /** @return array{xml: string, etag: string, generated_at: string}|null The last good file of an active feed */
    public function file(string $id): ?array
    {
        $row = DB::table('ambresoft_product_feed_files as f')->join(self::TABLE.' as p', 'p.id', '=', 'f.feed_id')
            ->where('f.feed_id', $id)->where('p.status', 'active')->first(['f.xml', 'f.etag', 'f.generated_at']);

        return $row === null ? null : (array) $row;
    }

    /** @return list<CatalogCategory> Public categories, the only ones a feed can include */
    public function categories(): array
    {
        try {
            return $this->catalog->categories();
        } catch (CatalogUnavailable) {
            return [];
        }
    }

    /**
     * Builds the complete file and replaces the stored one in a single write. A failure keeps the previous file and
     * records a safe message.
     */
    public function generate(string $id): void
    {
        $feed = $this->find($id);
        if ($feed === null) {
            return;
        }
        try {
            $status = $this->catalog->status();
            if (! $status->open || ! $status->pricesVisible) {
                throw new RuntimeException(__('cms-product-feeds::ui.'.($status->open ? 'error_prices_hidden' : 'error_closed')));
            }
            $locale = $feed['locale_id'] === null ? null : app(LocaleCatalog::class)->active()->firstWhere('id', $feed['locale_id'])?->code;
            $xml = new FeedXml($feed, $feed['name'], url('/'), $status->decimals, $this->catalog->categories($locale), new DateTimeImmutable);
            $seen = [];
            foreach ($feed['scope'] === 'categories' ? $feed['categories'] : [null] as $category) {
                for ($page = 1; ; $page++) {
                    $result = $this->catalog->searchProducts(new CatalogQuery(categoryId: $category, includeDescendants: $category !== null,
                        inStockOnly: $feed['in_stock_only'], page: $page, perPage: CatalogQuery::MAX_PER_PAGE, locale: $locale));
                    foreach ($result->products as $product) {
                        if (! isset($seen[$product->id])) {
                            $seen[$product->id] = true;
                            $xml->add($product);
                        }
                    }
                    if (! $result->hasMore) {
                        break;
                    }
                }
            }
            $file = $xml->finish();
        } catch (CatalogUnavailable $e) {
            $this->fail($id, __('cms-product-feeds::ui.error_'.($e->reason === 'closed' ? 'closed' : 'catalog')));

            return;
        } catch (RuntimeException $e) {
            $this->fail($id, $e->getMessage());

            return;
        }
        DB::transaction(function () use ($id, $file, $xml): void {
            DB::table('ambresoft_product_feed_files')->upsert([['feed_id' => $id, 'xml' => $file, 'etag' => hash('sha256', $file), 'generated_at' => now()]],
                ['feed_id'], ['xml', 'etag', 'generated_at']);
            DB::table(self::TABLE)->where('id', $id)->update(['product_count' => $xml->count(), 'generated_at' => now(), 'last_error' => null]);
        });
    }

    public function fail(string $id, string $message): void
    {
        DB::table(self::TABLE)->where('id', $id)->update(['last_error' => Str::limit($message, 490)]);
    }
}
