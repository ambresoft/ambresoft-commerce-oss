<?php

namespace App\Media\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Private MP4 storage for background videos: content-sniffed (ISO base media "ftyp" box), at most 16 MiB. */
final class PrivateVideoStorage
{
    public const MAX_BYTES = 16777216;

    /** @return array{disk: string, storage_key: string, mime_type: string, byte_size: int} */
    public function store(UploadedFile $file, string $field, int $maxBytes = self::MAX_BYTES, string $message = 'banners.video_invalid'): array
    {
        $path = $file->getPathname();
        $size = is_file($path) ? filesize($path) : false;
        $head = $size ? (string) file_get_contents($path, false, null, 0, 12) : '';
        $brand = substr($head, 8, 4);
        if (! $size || $size > $maxBytes || substr($head, 4, 4) !== 'ftyp' || ! in_array($brand, ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash'], true)) {
            throw ValidationException::withMessages([$field => __($message)]);
        }
        $key = 'videos/'.Str::uuid().'.mp4';
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read validated video.');
        }
        try {
            if (! Storage::disk('local')->put($key, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Unable to store private video.');
            }
        } catch (Throwable $exception) {
            $this->discard($key);
            throw $exception;
        } finally {
            fclose($stream);
        }

        return ['disk' => 'local', 'storage_key' => $key, 'mime_type' => 'video/mp4', 'byte_size' => $size];
    }

    public function discard(string $key): void
    {
        try {
            if (Storage::disk('local')->delete($key)) {
                return;
            }
        } catch (Throwable) {
            // Cleanup must not mask the original failure.
        }
        Log::warning('Private video rollback cleanup failed.', ['storage_key' => $key]);
    }

    /** Range-capable response so browsers (Safari in particular) can play and seek. */
    public function response(string $id): BinaryFileResponse
    {
        $video = DB::table('media_videos')->where('id', $id)->first();
        abort_unless($video !== null && $video->disk === 'local' && Storage::disk('local')->exists($video->storage_key), 404);
        app(AuditLogger::class)->record(new AuditEvent('media.read', 'media_video', (string) $video->id));

        return response()->file(Storage::disk('local')->path($video->storage_key), ['Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
