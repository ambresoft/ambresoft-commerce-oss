<?php

namespace App\Storefront\Infrastructure;

/** Preload hints for the first banner (or all banners) of the first banner block that asks for it (the hero everyone sees first). */
final class BannerPreload
{
    private const MEDIA = ['phone' => '(max-width: 767px)', 'tablet' => '(min-width: 768px) and (max-width: 1023px)', 'pc' => '(min-width: 1024px)'];

    /** @param array<string,mixed> $page the public page payload from LayoutRenderer
     * @return list<array{href:string,media:string}> */
    public function links(array $page): array
    {
        foreach ((array) ($page['regions'] ?? []) as $nodes) {
            if (($found = $this->firstBlock($nodes)) !== null) {
                return $this->images($found);
            }
        }

        return [];
    }

    /** @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>|null The banners to preload: the block's first, or all of them. */
    private function firstBlock(array $nodes): ?array
    {
        foreach ($nodes as $grid) {
            foreach ($grid['blocks'] as $block) {
                $preload = $block['content']['display']['preload'] ?? 'none';
                if (str_starts_with($block['template'], 'banner_') && in_array($preload, ['first', 'all'], true) && ($block['content']['items'] ?? []) !== []) {
                    return $preload === 'all' ? $block['content']['items'] : [$block['content']['items'][0]];
                }
            }
            if (($child = $this->firstBlock($grid['children'])) !== null) {
                return $child;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $items
     * @return list<array{href:string,media:string}> */
    private function images(array $items): array
    {
        $links = [];
        foreach ($items as $item) {
            foreach (self::MEDIA as $device => $media) {
                $content = $item['banner']['devices'][$device]['content'] ?? [];
                foreach (['main_image', 'background_image'] as $slot) {
                    $href = $content[$slot] ?? null;
                    if (is_string($href) && str_starts_with($href, '/storefront/media/')) {
                        $links[$href.'|'.$media] = ['href' => $href, 'media' => $media];
                    }
                }
            }
        }

        return array_values($links);
    }
}
