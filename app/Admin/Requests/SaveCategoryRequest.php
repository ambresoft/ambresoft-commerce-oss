<?php

namespace App\Admin\Requests;

use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Infrastructure\CategoryInput;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->route('category') === null ? 'categories.create' : 'categories.update');
    }

    protected function prepareForValidation(): void
    {
        // Multipart FormData omits an empty object. On update, omitted locale rows mean unchanged.
        if ($this->route('category') !== null && ! $this->exists('translations')) {
            $this->merge(['translations' => []]);
        }
        if (is_array($this->input('translations'))) {
            $this->merge(['translations' => app(CategoryInput::class)->normalize($this->input('translations'))]);
        }
        if ($this->boolean('faqs_submitted') && ! $this->exists('faq_ids')) {
            $this->merge(['faq_ids' => []]);
        }
        if ($this->boolean('blog_posts_submitted') && ! $this->exists('blog_post_ids')) {
            $this->merge(['blog_post_ids' => []]);
        }
        $this->merge(['remove_image' => $this->input('remove_image', false), 'remove_block_image' => $this->input('remove_block_image', false), 'remove_menu_icon' => $this->input('remove_menu_icon', false), 'remove_banner_image' => $this->input('remove_banner_image', false)]);
        if ($this->boolean('customer_groups_submitted') && ! $this->exists('customer_groups')) {
            $this->merge(['customer_groups' => []]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $image = ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', Rule::dimensions()->maxWidth(6000)->maxHeight(6000)];

        return [...app(CategoryInput::class)->rules($this->route('category')), 'image' => $image, 'block_image' => $image, 'menu_icon' => $image, 'banner_image' => $image, 'blog_post_ids' => ['sometimes', 'array', 'max:50'], 'blog_post_ids.*' => ['integer', 'min:1', 'distinct']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return app(CategoryInput::class)->attributes();
    }

    public function toData(): SaveCategoryData
    {
        $image = $this->file('image');
        $blockImage = $this->file('block_image');
        $bannerImage = $this->file('banner_image');
        $menuIcon = $this->file('menu_icon');

        return new SaveCategoryData($this->validated('parent_id') === null ? null : (string) $this->validated('parent_id'), (int) $this->validated('position'), $this->validated('status'), $this->validated('translations'), $this->validated('version') === null ? null : (int) $this->validated('version'), $image === null ? null : new ImageUpload($image->getPathname()), (bool) $this->validated('remove_image'), $this->validated('age_verification') === null ? null : (bool) $this->validated('age_verification'), $this->validated('age_limit') === null ? null : (int) $this->validated('age_limit'), $this->validated('exclude_from_discovery') === null ? null : (bool) $this->validated('exclude_from_discovery'), $this->validated('customer_groups') === null ? null : array_map(strval(...), $this->validated('customer_groups')), faqIds: $this->validated('faq_ids') === null ? null : array_map(strval(...), $this->validated('faq_ids')), faqDrafts: $this->validated('faq_drafts') ?? [], blockImage: $blockImage === null ? null : new ImageUpload($blockImage->getPathname()), removeBlockImage: (bool) $this->validated('remove_block_image'), bannerImage: $bannerImage === null ? null : new ImageUpload($bannerImage->getPathname()), removeBannerImage: (bool) $this->validated('remove_banner_image'), bannerLink: $this->exists('banner_link') ? trim((string) $this->validated('banner_link')) : null, isLandingPage: $this->validated('is_landing_page') === null ? null : (bool) $this->validated('is_landing_page'),
            // Only staff who may see blogs change a category's posts; otherwise the saved ones stay.
            menuIcon: $menuIcon === null ? null : new ImageUpload($menuIcon->getPathname()), removeMenuIcon: (bool) $this->validated('remove_menu_icon'), blogPostIds: $this->exists('blog_post_ids') && $this->user()?->can('blog.view') === true ? array_map(strval(...), $this->validated('blog_post_ids')) : null, excludeFromSitemap: $this->validated('exclude_from_sitemap') === null ? null : (bool) $this->validated('exclude_from_sitemap'));
    }
}
