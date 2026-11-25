<?php

namespace App\Shared\Infrastructure;

use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;

/** Pictures for sample data: generated gradients (pure PHP) or the bundled CC0 demo photos in database/demo/images. */
final class DemoPicture
{
    /** Stores a vertical gradient between two #rrggbb colors as a private image and returns its media file id. */
    public function create(string $from, string $to, int $size = 600, ?int $height = null): int
    {
        [$r1, $g1, $b1] = array_map(hexdec(...), str_split(ltrim($from, '#'), 2));
        [$r2, $g2, $b2] = array_map(hexdec(...), str_split(ltrim($to, '#'), 2));
        $height ??= $size;
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $f = $y / max(1, $height - 1);
            $pixel = chr((int) round($r1 + ($r2 - $r1) * $f)).chr((int) round($g1 + ($g2 - $g1) * $f)).chr((int) round($b1 + ($b2 - $b1) * $f));
            $rows .= "\0".str_repeat($pixel, $size);
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $size, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($rows, 9)).$chunk('IEND', '');
        $path = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($path, $png);
        $stored = app(PrivateImageStorage::class)->store(new ImageUpload($path));
        @unlink($path);

        return (int) MediaFileRecord::create($stored)->id;
    }

    /** Stores a copy of a bundled demo picture (database/demo/images/{name}.jpg or .png) as a private image and returns its media file id. */
    public function photo(string $name): int
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        $source = database_path('demo/images/'.$name.'.jpg');
        copy(is_file($source) ? $source : database_path('demo/images/'.$name.'.png'), $path);
        $stored = app(PrivateImageStorage::class)->store(new ImageUpload($path));
        @unlink($path);

        return (int) MediaFileRecord::create($stored)->id;
    }
}
