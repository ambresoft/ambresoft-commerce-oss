<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Media\Infrastructure\Persistence\MediaFileRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $exclude_from_sitemap
 * @property bool $is_landing_page
 * @property string|null $banner_link
 */
final class CategoryRecord extends Model
{
    protected $table = 'categories';

    protected $fillable = ['parent_id', 'position', 'status', 'age_verification', 'age_limit', 'exclude_from_discovery', 'exclude_from_sitemap'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'lock_version' => 'integer', 'age_verification' => 'boolean', 'age_limit' => 'integer', 'is_landing_page' => 'boolean', 'exclude_from_discovery' => 'boolean', 'exclude_from_sitemap' => 'boolean'];
    }

    /** @return HasMany<CategoryRecord, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<CategoryTranslationRecord, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslationRecord::class, 'category_id');
    }

    /** @return BelongsTo<MediaFileRecord, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaFileRecord::class, 'image_media_id');
    }

    /** @return BelongsTo<MediaFileRecord, $this> */
    public function menuIcon(): BelongsTo
    {
        return $this->belongsTo(MediaFileRecord::class, 'menu_icon_media_id');
    }

    /** @return BelongsTo<MediaFileRecord, $this> */
    public function blockImage(): BelongsTo
    {
        return $this->belongsTo(MediaFileRecord::class, 'block_image_media_id');
    }

    /** @return BelongsTo<MediaFileRecord, $this> */
    public function bannerImage(): BelongsTo
    {
        return $this->belongsTo(MediaFileRecord::class, 'banner_image_media_id');
    }
}
