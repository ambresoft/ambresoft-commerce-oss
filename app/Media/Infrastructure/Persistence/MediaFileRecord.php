<?php

namespace App\Media\Infrastructure\Persistence;

use App\Media\Infrastructure\SharedMediaDeletion;
use Illuminate\Database\Eloquent\Model;

final class MediaFileRecord extends Model
{
    protected $table = 'media_files';

    protected $fillable = ['disk', 'storage_key', 'mime_type', 'byte_size', 'width', 'height'];

    protected static function booted(): void
    {
        self::deleting(function (self $record): void {
            app(SharedMediaDeletion::class)->assertUnreferenced($record);
        });
    }
}
