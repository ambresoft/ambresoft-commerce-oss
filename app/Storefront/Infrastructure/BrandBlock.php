<?php

namespace App\Storefront\Infrastructure;

use App\Storefront\Application\Contract\BlockType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Composes the existing card-block settings, so product display controls have a single definition. */
final class BrandBlock implements BlockType
{
    /** Product sizing is independent of the logo tile dimensions. */
    public const PRODUCT_SIZES = ['product_per_row' => 'per_row', 'product_image_width' => 'image_width', 'product_image_height' => 'image_height'];

    public function __construct(private string $type) {}

    public function id(): string
    {
        return $this->type;
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $products = $this->type === 'brand_products';
        $definition = (new FirstPartyBlock($products ? 'products' : 'categories'))->definition();
        $definition['label'] = 'brands.block_'.$this->type;
        if ($products) {
            array_unshift($definition['fields'], ['key' => 'brand', 'kind' => 'selection', 'source' => 'brands', 'single' => true, 'label' => 'brands.brand']);
        } else {
            // Category card shadows are independent of the brand logo presentation.
            $definition['fields'] = array_values(array_filter($definition['fields'], fn ($field) => $field['key'] !== 'card_shadow'));
            unset($definition['defaults']['card_shadow']);
            $definition['defaults']['source'] = 'bestsellers';
            $definition['defaults']['image_width'] = 180;
            $definition['defaults']['image_height'] = 100;
            $definition['defaults']['per_row'] = 6;
            $definition['templates'] = ['brand_carousel', 'brand_single'];
            $definition['template_labels'] = ['brand_carousel' => 'brands.carousel', 'brand_single' => 'brands.single'];
            foreach ($definition['fields'] as &$field) {
                if ($field['key'] === 'source') {
                    $field['options'] = ['manual', 'bestsellers', 'alphabetical'];
                    $field['option_labels'] = ['manual' => 'layouts.source_manual', 'bestsellers' => 'layouts.source_bestsellers', 'alphabetical' => 'brands.alphabetical'];
                }
                if ($field['key'] === 'items') {
                    $field['source'] = 'brands';
                    $field['label'] = 'brands.items';
                }
            }
            unset($field);
            array_splice($definition['fields'], 1, 0, [['key' => 'brand', 'kind' => 'selection', 'source' => 'brands', 'single' => true, 'label' => 'brands.brand', 'when' => ['source' => 'bestsellers'], 'help' => 'brands.bestsellers_brand_help']]);
            $productDefinition = (new FirstPartyBlock('products'))->definition();
            foreach (self::PRODUCT_SIZES as $key => $productKey) {
                $field = array_values(array_filter($productDefinition['fields'], fn ($field) => $field['key'] === $productKey))[0];
                $definition['fields'][] = [...$field, 'key' => $key, 'label' => 'brands.'.$key, 'when' => ['source' => 'bestsellers'], 'when_selection' => 'brand'];
                $definition['defaults'][$key] = $productDefinition['defaults'][$productKey];
            }
        }

        $definition['defaults']['paid_ad'] = false;
        $definition['fields'][] = ['key' => 'paid_ad', 'kind' => 'boolean', 'label' => 'brands.paid_ad'];

        return $definition;
    }

    /** @param array<string,mixed> $settings
     * @return array<string,mixed> */
    public function validate(array $settings): array
    {
        $paidAd = Validator::make(['paid_ad' => $settings['paid_ad'] ?? false], ['paid_ad' => ['required', 'boolean']])->validate();
        unset($settings['paid_ad']);
        if ($this->type === 'brand_products') {
            return [...(new FirstPartyBlock('products'))->validate($settings), 'paid_ad' => (bool) $paidAd['paid_ad']];
        }
        $settings = array_replace($this->definition()['defaults'], $settings);
        unset($settings['paid_ad']);
        $source = $settings['source'];
        if (! in_array($source, ['manual', 'bestsellers', 'alphabetical'], true)) {
            throw ValidationException::withMessages(['source' => __('layouts.invalid')]);
        }
        $productSettings = [];
        foreach (self::PRODUCT_SIZES as $key => $productKey) {
            $productSettings[$productKey] = $settings[$key];
            unset($settings[$key]);
        }
        $productSettings = (new FirstPartyBlock('products'))->validate($productSettings);
        $valid = (new FirstPartyBlock('categories'))->validate([...$settings, 'source' => 'manual']);
        foreach (self::PRODUCT_SIZES as $key => $productKey) {
            $valid[$key] = $productSettings[$productKey];
        }
        unset($valid['card_shadow']);
        $valid['source'] = $source;
        $valid['paid_ad'] = (bool) $paidAd['paid_ad'];

        return $valid;
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string} $context
     * @return array<string,array<string,mixed>> */
    public function resolveMany(array $blocks, array $context): array
    {
        $content = app(BrandContent::class)->resolve($this->type, $blocks, $context);
        foreach ($content as $id => &$payload) {
            $payload['paid_ad'] = (bool) ($blocks[$id]['settings']['paid_ad'] ?? false);
            $payload['is_bestsellers'] = $blocks[$id]['settings']['source'] === 'bestsellers';
        }
        unset($payload);

        return $content;
    }
}
