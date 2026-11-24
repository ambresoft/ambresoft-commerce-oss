<?php

namespace App\Shared\Infrastructure\Modules;

use Illuminate\Support\Str;
use ZipArchive;

/**
 * Stages an uploaded add-on ZIP. Archives are executable code, so every entry is checked before anything is written:
 * entry count and size limits, compression ratio, safe relative names (no traversal, absolute paths, backslashes,
 * drive letters, control characters or case-insensitive duplicates), no symbolic links or encrypted entries, and an
 * allow-list of file types. Entries are streamed one by one into a fresh private staging directory (never
 * ZipArchive::extractTo) and the manifest is validated there. Nothing is installed or executed here.
 */
final class AddonPackage
{
    public const MAX_ENTRIES = 2000;

    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    public const MAX_FILE_BYTES = 16 * 1024 * 1024;

    private const MAX_RATIO = 1000;

    private const EXTENSIONS = ['php', 'json', 'md', 'txt', 'js', 'mjs', 'css', 'ts', 'tsx', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'csv', 'yml', 'yaml', 'xml', 'neon', 'lock', 'dist', 'stub', 'html'];

    private const PLAIN_NAMES = ['LICENSE', 'README', 'CHANGELOG', 'NOTICE', 'AUTHORS', 'COPYING'];

    /** Harmless archive clutter that is skipped rather than refused. */
    private const SKIPPED = ['.DS_Store', '.gitignore', '.gitattributes', '.editorconfig'];

    public function stagingRoot(): string
    {
        return storage_path('app/private/addon-staging');
    }

    /**
     * @return array{directory: string, manifest: ModuleManifest}
     *
     * @throws AddonException
     */
    public function stage(string $zipPath): array
    {
        $this->prune();
        $max = (int) config('modules.upload_max_kb', 20480) * 1024;
        if (! is_file($zipPath) || filesize($zipPath) === 0 || filesize($zipPath) > $max) {
            throw new AddonException('addons.error_zip_size', ['max' => (int) ($max / 1024 / 1024)]);
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new AddonException('addons.error_zip_invalid');
        }
        $directory = $this->stagingRoot().'/'.Str::uuid()->toString();
        try {
            $files = $this->entries($zip);
            $base = $this->base(array_keys($files));
            if (! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new AddonException('addons.error_staging');
            }
            foreach ($files as $name => $entry) {
                if ($base !== '' && ! str_starts_with($name, $base)) {
                    continue;
                }
                $this->extract($zip, $entry['index'], $entry['size'], $directory, substr($name, strlen($base)));
            }
            $manifest = ModuleManifest::fromDirectory($directory);

            return ['directory' => $directory, 'manifest' => $manifest];
        } catch (\Throwable $e) {
            $this->discard($directory);
            throw $e instanceof AddonException ? $e : new AddonException('addons.error_zip_invalid');
        } finally {
            $zip->close();
        }
    }

    public function discard(string $directory): void
    {
        if (str_starts_with($directory, $this->stagingRoot().'/')) {
            self::remove($directory);
        }
    }

    /** Deletes a directory tree without following symbolic links. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::remove($path.'/'.$item);
            }
        }
        @rmdir($path);
    }

    /**
     * Validates every entry and returns the files to extract, keyed by their normalized name.
     *
     * @return array<string, array{index: int, size: int}>
     */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new AddonException('addons.error_zip_entries', ['max' => self::MAX_ENTRIES]);
        }
        $files = [];
        $seen = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new AddonException('addons.error_zip_invalid');
            }
            $name = (string) $stat['name'];
            if (str_starts_with($name, '__MACOSX/') || in_array(basename($name), self::SKIPPED, true)) {
                continue;
            }
            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;
            if (! $this->safeName($path)) {
                throw new AddonException('addons.error_zip_path', ['entry' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '?', $name) ?? '', 0, 120)]);
            }
            if ($stat['encryption_method'] !== 0) {
                throw new AddonException('addons.error_zip_encrypted');
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0xF000) === 0xA000) {
                throw new AddonException('addons.error_zip_link', ['entry' => $path]);
            }
            $key = strtolower($path);
            if (isset($seen[$key])) {
                throw new AddonException('addons.error_zip_duplicate', ['entry' => $path]);
            }
            $seen[$key] = true;
            if ($directory) {
                continue;
            }
            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];
            $total += $size;
            if ($size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                throw new AddonException('addons.error_zip_uncompressed', ['max' => (int) (self::MAX_TOTAL_BYTES / 1024 / 1024)]);
            }
            if ($size > 1024 * 1024 && ($compressed === 0 || $size / $compressed > self::MAX_RATIO)) {
                throw new AddonException('addons.error_zip_ratio');
            }
            if (! $this->allowedFile(basename($path))) {
                throw new AddonException('addons.error_zip_type', ['entry' => $path]);
            }
            $files[$path] = ['index' => $index, 'size' => $size];
        }
        // A file and a directory of the same name (or a file "inside" a file) is not a valid tree.
        foreach (array_keys($files) as $path) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($files[$parent])) {
                    throw new AddonException('addons.error_zip_path', ['entry' => $path]);
                }
            }
        }

        return $files;
    }

    /**
     * The package root inside the archive: the archive root, or its single top-level folder.
     *
     * @param  list<string>  $names
     */
    private function base(array $names): string
    {
        if (in_array('composer.json', $names, true)) {
            return '';
        }
        $tops = array_unique(array_map(fn (string $name): string => explode('/', $name, 2)[0], $names));
        if (count($tops) === 1 && in_array($tops[0].'/composer.json', $names, true)) {
            return $tops[0].'/';
        }
        throw new AddonException('addons.error_zip_manifest');
    }

    private function safeName(string $path): bool
    {
        if ($path === '' || strlen($path) > 255 || ! mb_check_encoding($path, 'UTF-8') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            // Letters, digits and . _ - @ + only; no empty, "." or ".." segments, no hidden files or folders.
            if (preg_match('/^[A-Za-z0-9_@+-][A-Za-z0-9._@+-]*$/D', $segment) !== 1 || strlen($segment) > 120) {
                return false;
            }
        }

        return true;
    }

    private function allowedFile(string $basename): bool
    {
        if (in_array($basename, self::PLAIN_NAMES, true)) {
            return true;
        }
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) && ! preg_match('/\.(phar|phtml|php\d|pht)(\.|$)/i', $basename);
    }

    private function extract(ZipArchive $zip, int $index, int $size, string $directory, string $relative): void
    {
        $target = $directory.'/'.$relative;
        $parent = dirname($target);
        if (! is_dir($parent) && ! @mkdir($parent, 0750, true)) {
            throw new AddonException('addons.error_staging');
        }
        $real = realpath($parent);
        $root = realpath($directory);
        if ($real === false || $root === false || ($real !== $root && ! str_starts_with($real.'/', $root.'/'))) {
            throw new AddonException('addons.error_zip_path', ['entry' => $relative]);
        }
        $source = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
        $handle = @fopen($target, 'xb');
        if ($source === false || $handle === false) {
            throw new AddonException('addons.error_staging');
        }
        try {
            $written = 0;
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new AddonException('addons.error_zip_invalid');
                }
                $written += strlen($chunk);
                // The declared size is a claim; the bytes actually produced are what is limited.
                if ($written > $size) {
                    throw new AddonException('addons.error_zip_ratio');
                }
                fwrite($handle, $chunk);
            }
            if ($written !== $size) {
                throw new AddonException('addons.error_zip_invalid');
            }
        } finally {
            fclose($source);
            fclose($handle);
        }
    }

    /** Removes staging directories left behind by interrupted uploads. */
    private function prune(): void
    {
        foreach (glob($this->stagingRoot().'/*', GLOB_ONLYDIR) ?: [] as $stale) {
            if (filemtime($stale) < time() - 86400) {
                self::remove($stale);
            }
        }
    }
}
