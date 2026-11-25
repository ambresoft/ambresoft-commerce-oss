<?php

namespace App\Media\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class PrivateImageStorage
{
    /** @return array{disk: string, storage_key: string, mime_type: string, byte_size: int, width: int, height: int} */
    public function store(ImageUpload $upload): array
    {
        $metadata = $this->inspect($upload);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$metadata['mime_type']];
        $key = 'images/'.Str::uuid().'.'.$extension;
        $stream = fopen($upload->temporaryPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read validated image.');
        }
        try {
            if (! Storage::disk('local')->put($key, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Unable to store private image.');
            }
        } catch (Throwable $exception) {
            $this->discard($key);
            throw $exception;
        } finally {
            fclose($stream);
        }

        return ['disk' => 'local', 'storage_key' => $key, ...$metadata];
    }

    /** @return array{mime_type: string, byte_size: int, width: int, height: int} */
    public function inspect(ImageUpload $upload): array
    {
        clearstatcache(true, $upload->temporaryPath);
        $size = is_file($upload->temporaryPath) ? filesize($upload->temporaryPath) : false;
        $dimensions = $size ? @getimagesize($upload->temporaryPath) : false;
        $mime = $dimensions['mime'] ?? '';
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $size || $size > 5242880 || ! $dimensions || $extension === null || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
            throw ValidationException::withMessages(['image' => __('categories.errors.image')]);
        }

        return ['mime_type' => $mime, 'byte_size' => $size, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    public function discard(string $key): void
    {
        // Compensate a failed SQL operation. No credentials or client filenames in logs.
        try {
            if (Storage::disk('local')->delete($key)) {
                return;
            }
        } catch (Throwable) {
            // Cleanup must not mask the original storage/database failure.
        }
        Log::warning('Private image rollback cleanup failed.', ['storage_key' => $key]);
    }

    public function response(MediaFileRecord $image): StreamedResponse
    {
        abort_unless($image->disk === 'local' && Storage::disk('local')->exists($image->storage_key), 404);

        app(AuditLogger::class)->record(new AuditEvent('media.read', 'media', (string) $image->id));

        return Storage::disk('local')->response($image->storage_key, 'category-image', ['Content-Type' => $image->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
