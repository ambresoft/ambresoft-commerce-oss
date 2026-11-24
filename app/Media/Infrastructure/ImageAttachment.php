<?php

namespace App\Media\Infrastructure;

use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Stores single optional images of records saved in one versioned transaction (payment and shipping methods).
 * Files are written before the owner's row changes; `discardUncommitted()` removes files whose media row did not
 * survive a failed save. Replaced images are kept, like other media, for the retention policy.
 */
final class ImageAttachment
{
    /** @var list<string> */
    private array $stored = [];

    public function __construct(private PrivateImageStorage $storage) {}

    /** Validates and stores an upload inside the caller's transaction; returns the media file ID. */
    public function store(UploadedFile $file, string $field = 'image'): string
    {
        try {
            $info = $this->storage->store(new ImageUpload($file->getPathname()));
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => __('categories.errors.image')]);
        }
        $this->stored[] = $info['storage_key'];

        return (string) MediaFileRecord::create($info)->id;
    }

    public function discardUncommitted(): void
    {
        foreach ($this->stored as $key) {
            if (! MediaFileRecord::where('storage_key', $key)->exists()) {
                $this->storage->discard($key);
            }
        }
        $this->stored = [];
    }

    /** @return array{url: string, meta: array{size: int, width: int, height: int}}|null */
    public static function view(mixed $mediaId, string $url): ?array
    {
        $file = $mediaId === null ? null : MediaFileRecord::find($mediaId);

        return $file === null ? null : ['url' => $url.'?v='.rawurlencode((string) $mediaId), 'meta' => ['size' => (int) $file->byte_size, 'width' => (int) $file->width, 'height' => (int) $file->height]];
    }
}
