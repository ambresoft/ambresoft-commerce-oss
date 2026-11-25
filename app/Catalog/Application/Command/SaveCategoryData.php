<?php

namespace App\Catalog\Application\Command;

use App\Shared\Application\Command\ImageUpload;

final readonly class SaveCategoryData
{
    /**
     * @param  array<string, array{name: string, description: string, seo_title: string, seo_description: string, slug: string, image_alt: string, meta_keywords?: string, age_warning_message?: string}>  $translations
     * @param  list<string>|null  $customerGroups  Visibility restriction (empty: every visitor); null keeps the saved one.
     * @param  list<string>|null  $faqIds
     * @param  list<array{translations: array<string, array{question: string, answer: string}>}>  $faqDrafts
     * @param  list<string>|null  $blogPostIds
     */
    public function __construct(
        public ?string $parentId,
        public int $position,
        public string $status,
        public array $translations,
        public ?int $version = null,
        public ?ImageUpload $image = null,
        public bool $removeImage = false,
        public ?bool $ageVerification = null,
        public ?int $ageLimit = null,
        public ?bool $excludeFromDiscovery = null,
        public ?array $customerGroups = null,
        public ?array $faqIds = null,
        public array $faqDrafts = [],
        public ?ImageUpload $blockImage = null,
        public bool $removeBlockImage = false,
        public ?ImageUpload $bannerImage = null,
        public bool $removeBannerImage = false,
        /** Where the banner image links to; '' removes the link, null keeps the saved one. */
        public ?string $bannerLink = null,
        public ?bool $isLandingPage = null,
        /** Blog posts shown on the category page in this order; null keeps the saved ones. */
        public ?array $blogPostIds = null,
        /** The menu icon, separate from the category image. */
        public ?ImageUpload $menuIcon = null,
        public bool $removeMenuIcon = false,
        public ?bool $excludeFromSitemap = null,
    ) {}
}
