<?php

namespace Ambresoft\ProductFeeds\Application\Service;

use App\Shared\Domain\Service\Decimal;
use App\Storefront\Application\Command\CatalogCategory;
use App\Storefront\Application\Command\CatalogProduct;
use DateTimeImmutable;
use XMLWriter;

/**
 * Writes one RSS 2.0 product feed in the Google Merchant Center namespace, which Meta catalogs read too.
 * Items missing a value the channel requires (link, image, price, a valid id; for Meta also a brand, GTIN or MPN) are
 * skipped rather than sent to be disapproved.
 *
 * @phpstan-type Settings array{channel: string, id_source: string, description_source: string, description_limit: ?int,
 *     title_for_empty_description: bool, empty_description: string, attributes: list<array{attribute: string, feature: string, fallback: string}>,
 *     apply_promotions: bool, use_list_price: bool, discount_label: bool, show_categories: bool, additional_images: bool,
 *     min_price: ?string, show_weight: bool}
 */
final class FeedXml
{
    public const CHANNELS = ['google', 'meta'];

    /** Attributes a merchant may fill from a product feature or a fixed value. */
    public const ATTRIBUTES = ['brand', 'gtin', 'mpn', 'condition', 'color', 'size', 'gender', 'age_group', 'material', 'pattern',
        'google_product_category', 'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4'];

    public const NAMESPACE = 'http://base.google.com/ns/1.0';

    /** Channel limits from the Google product data specification and Meta's catalog field reference. */
    private const LIMITS = [
        'google' => ['id' => 50, 'title' => 150, 'description' => 5000, 'brand' => 70, 'mpn' => 70],
        'meta' => ['id' => 100, 'title' => 200, 'description' => 9999, 'brand' => 100, 'mpn' => 100],
    ];

    private const LENGTHS = ['color' => 100, 'size' => 100, 'gender' => 20, 'age_group' => 20, 'material' => 200, 'pattern' => 100,
        'google_product_category' => 750, 'product_type' => 750, 'custom_label' => 100];

    private XMLWriter $xml;

    private int $count = 0;

    /** @var array<string, CatalogCategory> */
    private array $categories = [];

    /** @var array<string, string> */
    private array $paths = [];

    /**
     * @param  Settings  $settings
     * @param  list<CatalogCategory>  $categories
     */
    public function __construct(private array $settings, string $title, string $link, private int $decimals, array $categories, private DateTimeImmutable $now)
    {
        foreach ($categories as $category) {
            $this->categories[$category->id] = $category;
        }
        $this->xml = new XMLWriter;
        $this->xml->openMemory();
        // One element per line: Meta rejects a feed line longer than 5,242,880 characters.
        $this->xml->setIndent(true);
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement('rss');
        $this->xml->writeAttribute('version', '2.0');
        $this->xml->writeAttribute('xmlns:g', self::NAMESPACE);
        $this->xml->startElement('channel');
        $this->xml->writeElement('title', self::text($title));
        $this->xml->writeElement('link', $link);
        $this->xml->writeElement('description', self::text($title));
    }

    /** Adds a product, or returns false when it cannot be a valid item or the price limit leaves it out. */
    public function add(CatalogProduct $product): bool
    {
        $s = $this->settings;
        $limits = self::LIMITS[$s['channel']];
        $id = $s['id_source'] === 'sku' && $product->sku !== '' ? $product->sku : $product->id;
        $pricing = $product->pricing;
        if ($product->url === null || $product->images === [] || $pricing === null || mb_strlen($id) > $limits['id'] || self::text($product->name) === '') {
            return false;
        }
        $current = $s['apply_promotions'] ? $pricing->current() : $pricing->regular;
        if ($s['min_price'] !== null && Decimal::compare($current, $s['min_price']) <= 0) {
            return false;
        }
        $price = $s['use_list_price'] && $pricing->list !== null && Decimal::compare($pricing->list, $current) > 0 ? $pricing->list : $pricing->regular;
        $values = $this->mapped($product);
        $brand = self::cut($values['brand'] ?? $product->brand['name'] ?? '', $limits['brand']);
        $gtin = (string) preg_replace('/[\s-]+/', '', $values['gtin'] ?? $product->gtin ?? '');
        $gtin = preg_match('/^(\d{8}|\d{12,14})$/D', $gtin) === 1 ? $gtin : '';
        $mpn = self::cut($values['mpn'] ?? '', $limits['mpn']);
        // Meta rejects an item without any of brand, GTIN or MPN.
        if ($s['channel'] === 'meta' && $brand === '' && $gtin === '' && $mpn === '') {
            return false;
        }

        $this->xml->startElement('item');
        $this->g('id', $id);
        $this->g('title', self::cut($product->name, $limits['title']));
        $this->g('description', self::cut($this->description($product), min($s['description_limit'] ?? PHP_INT_MAX, $limits['description'])));
        $this->g('link', $product->url);
        $this->g('image_link', $product->images[0]);
        foreach ($s['additional_images'] ? array_slice($product->images, 1, 10) : [] as $image) {
            $this->g('additional_image_link', $image);
        }
        $this->availability($product);
        $this->g('price', $this->money($price, $pricing->currency));
        if (Decimal::compare($current, $price) < 0) {
            $this->g('sale_price', $this->money($current, $pricing->currency));
        }
        $this->g('brand', $brand);
        $this->g('gtin', $gtin);
        $this->g('mpn', $mpn);
        if ($s['channel'] === 'google' && $gtin === '' && $mpn === '') {
            $this->g('identifier_exists', 'no');
        }
        $condition = mb_strtolower($values['condition'] ?? '');
        $this->g('condition', in_array($condition, ['new', 'refurbished', 'used'], true) ? $condition : 'new');
        $this->g('item_group_id', self::cut($product->variation ?? '', $limits['id']));
        if ($s['show_categories']) {
            $this->g('product_type', self::cut($this->path($product->category), self::LENGTHS['product_type']));
        }
        if ($s['discount_label'] && ($product->discountPercent ?? 0) > 0 && ! isset($values['custom_label_3'])) {
            $values['custom_label_3'] = $product->discountPercent.'%';
        }
        if ($s['show_weight'] && $product->weightGrams !== null && Decimal::compare($product->weightGrams, '0') > 0) {
            $this->g('shipping_weight', Decimal::trim(Decimal::round($product->weightGrams, 2)).' g');
        }
        foreach ($values as $attribute => $value) {
            if (! in_array($attribute, ['brand', 'gtin', 'mpn', 'condition'], true)) {
                $this->g($attribute, self::cut($value, self::LENGTHS[str_starts_with($attribute, 'custom_label') ? 'custom_label' : $attribute]));
            }
        }
        $this->xml->endElement();
        $this->count++;

        return true;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function finish(): string
    {
        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->endDocument();

        return $this->xml->outputMemory();
    }

    /** Removes characters XML 1.0 cannot carry and collapses whitespace; invalid UTF-8 becomes empty. */
    public static function text(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{7E}\x{85}\x{A0}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function cut(string $value, int $length): string
    {
        $value = self::text($value);

        return mb_strlen($value) > $length ? rtrim(mb_substr($value, 0, $length)) : $value;
    }

    /** Writes a g: element; Google ignores empty attributes, so none are written. */
    private function g(string $name, string $value): void
    {
        if ($value !== '') {
            $this->xml->writeElementNs('g', $name, null, $value);
        }
    }

    private function description(CatalogProduct $product): string
    {
        $s = $this->settings;
        $text = self::text($s['description_source'] === 'short' ? $product->shortDescription : $product->description);
        if ($text === '') {
            $text = self::text($s['empty_description']);
        }

        return $text === '' && $s['title_for_empty_description'] ? $product->name : $text;
    }

    /**
     * Google requires availability_date for preorder and backorder. The CMS knows no restock date, so a backorder is
     * reported as out of stock rather than with an invented date. Meta knows only "in stock" and "out of stock".
     */
    private function availability(CatalogProduct $product): void
    {
        $a = $product->availability;
        if ($this->settings['channel'] === 'meta') {
            $this->g('availability', $a->status === 'in_stock' && $a->purchasable ? 'in stock' : 'out of stock');

            return;
        }
        $preorder = $a->status === 'upcoming' && $a->purchasable && $a->availableFrom !== null
            && $a->availableFrom <= $this->now->modify('+1 year')->format('Y-m-d');
        $this->g('availability', match (true) {
            $preorder => 'preorder',
            $a->status === 'in_stock' && $a->purchasable => 'in_stock',
            default => 'out_of_stock',
        });
        if ($preorder) {
            $this->g('availability_date', $a->availableFrom.'T00:00Z');
        }
    }

    private function money(string $amount, string $currency): string
    {
        return Decimal::round($amount, $this->decimals).' '.$currency;
    }

    /** Category names from the root down, separated by " > " as both channels expect. */
    private function path(CatalogCategory $category): string
    {
        if (! isset($this->paths[$category->id])) {
            $names = [];
            for ($c = $category, $depth = 0; $c !== null && $depth < 50; $c = $c->parentId === null ? null : ($this->categories[$c->parentId] ?? null), $depth++) {
                array_unshift($names, self::text($c->name));
            }
            $this->paths[$category->id] = implode(' > ', $names);
        }

        return $this->paths[$category->id];
    }

    /**
     * Mapped attribute values: the product's feature (by code or name, case ignored) or else the fixed fallback.
     *
     * @return array<string, string>
     */
    private function mapped(CatalogProduct $product): array
    {
        $values = [];
        foreach ($this->settings['attributes'] as $map) {
            $value = '';
            $feature = mb_strtolower($map['feature']);
            foreach ($feature === '' ? [] : $product->attributes as $attribute) {
                if (mb_strtolower($attribute['code'] ?? '') === $feature || mb_strtolower($attribute['name']) === $feature) {
                    $value = self::text($attribute['value']);
                    break;
                }
            }
            $value = $value !== '' ? $value : self::text($map['fallback']);
            if ($value !== '') {
                $values[$map['attribute']] = $value;
            }
        }

        return $values;
    }
}
