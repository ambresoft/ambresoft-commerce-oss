<?php

namespace App\Storefront\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Private drafts of existing theme sources. Saving never changes executable assets. */
final class ThemeCodeEditor
{
    public const ROOT = 'resources/js/storefront/themes/Default';

    public const LIMIT = 262144;

    public function __construct(private ?string $project = null, private ?string $drafts = null) {}

    private function project(): string
    {
        return $this->project ?? base_path();
    }

    private function drafts(): string
    {
        return $this->drafts ?? storage_path('app/private/theme-editor');
    }

    /** @return list<string> */
    public function files(): array
    {
        Gate::authorize('theme-code.manage');
        $this->scope();
        $root = $this->project().'/'.self::ROOT;
        $this->regularPath(self::ROOT, directory: true);
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($this->allowed($relative)) {
                try {
                    $this->source($relative);
                    $files[] = $relative;
                } catch (BadRequestException|HttpException $e) {
                    continue;
                }
            }
            if (count($files) >= 500) {
                break;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return array{path:string,content:string,version:string,pending:bool,conflict:bool} */
    public function read(string $path): array
    {
        Gate::authorize('theme-code.manage');

        return $this->locked(fn () => $this->snapshot($path));
    }

    /** @return array{path:string,content:string,version:string,pending:bool,conflict:bool} */
    public function save(string $path, string $content, string $version): array
    {
        Gate::authorize('theme-code.manage');
        if (strlen($content) > self::LIMIT || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw ValidationException::withMessages(['content' => __('code_editor.size')]);
        }

        return $this->locked(function () use ($path, $content, $version): array {
            $current = $this->snapshot($path);
            abort_unless(hash_equals($current['version'], $version), 409, __('code_editor.stale'));
            abort_if($current['conflict'], 409, __('code_editor.source_changed'));
            $source = $this->source($path);
            $target = $this->draftFile($path);
            $previous = is_file($target) ? file_get_contents($target) : null;
            $draft = json_encode(['path' => $path, 'base' => hash('sha256', $source), 'content' => $content], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            try {
                DB::transaction(function () use ($path, $target, $draft, $content, $source): void {
                    app(AuditLogger::class)->record(new AuditEvent('theme_code.saved', 'theme_file', $path));
                    if ($content === $source) {
                        abort_if(is_file($target) && ! @unlink($target), 422, __('code_editor.write_failed'));
                    } else {
                        $this->replace($target, $draft);
                    }
                });
            } catch (Throwable $e) {
                if ($previous !== null) {
                    $this->replace($target, $previous);
                } elseif (is_file($target)) {
                    @unlink($target);
                }
                throw $e;
            }

            return $this->snapshot($path);
        });
    }

    /** @return array{path:string,content:string,version:string,pending:bool,conflict:bool} */
    private function snapshot(string $path): array
    {
        $source = $this->source($path);
        $target = $this->draftFile($path);
        abort_if(is_link($target), 422, __('code_editor.unavailable'));
        $stat = is_file($target) ? @stat($target) : null;
        abort_if($stat && ($stat['size'] > self::LIMIT * 8 || $stat['nlink'] !== 1), 422, __('code_editor.unavailable'));
        $raw = is_file($target) ? @file_get_contents($target, false, null, 0, self::LIMIT * 8) : '';
        abort_if($raw === false, 422, __('code_editor.unavailable'));
        $draft = $raw !== '' ? json_decode($raw, true) : null;
        abort_if($raw !== '' && (! is_array($draft) || ($draft['path'] ?? null) !== $path || ! is_string($draft['content'] ?? null) || ! is_string($draft['base'] ?? null)), 422, __('code_editor.unavailable'));

        abort_if($draft !== null && (strlen($draft['content']) > self::LIMIT || str_contains($draft['content'], "\0")), 422, __('code_editor.unavailable'));

        return ['path' => $path, 'content' => $draft['content'] ?? $source,
            'version' => hash('sha256', $source."\0".$raw),
            'pending' => $draft !== null && $draft['content'] !== $source,
            'conflict' => $draft !== null && $draft['base'] !== hash('sha256', $source) && $draft['content'] !== $source];
    }

    private function scope(): void
    {
        abort_unless(config('storefront.theme') === 'default', 422, __('code_editor.theme_unavailable'));
    }

    private function allowed(string $path): bool
    {
        return strlen($path) <= 180 && preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(css|tsx|jsx|ts|js)$~D', $path) === 1
            && ! preg_match('~(^|/)(node_modules|vendor|dist|build|config|tests|__tests__)(/|\.)~i', $path);
    }

    private function source(string $path): string
    {
        $this->scope();
        abort_unless($this->allowed($path), 404, __('code_editor.unavailable'));
        $full = $this->regularPath(self::ROOT.'/'.$path);
        $stat = @stat($full);
        abort_if(! $stat || $stat['size'] > self::LIMIT || $stat['nlink'] !== 1 || ! is_readable($full), 422, __('code_editor.unavailable'));
        $content = @file_get_contents($full, false, null, 0, self::LIMIT + 1);
        abort_if($content === false || strlen($content) > self::LIMIT || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0"), 422, __('code_editor.unavailable'));

        return $content;
    }

    private function regularPath(string $relative, bool $directory = false): string
    {
        $path = $this->project();
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            clearstatcache(true, $path);
            abort_if(is_link($path), 404, __('code_editor.unavailable'));
        }
        $real = realpath($path);
        abort_unless($real !== false && str_starts_with($real, realpath($this->project()).'/') && ($directory ? is_dir($real) : is_file($real)), 404, __('code_editor.unavailable'));

        return $real;
    }

    private function draftFile(string $path): string
    {
        return $this->drafts().'/'.hash('sha256', $path).'.json';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $directory = $this->drafts();
        abort_if(is_link($directory), 422, __('code_editor.write_failed'));
        abort_if(! is_dir($directory) && ! @mkdir($directory, 0700, true), 422, __('code_editor.write_failed'));
        $lock = $directory.'/lock';
        abort_unless(@mkdir($lock, 0700), 409, __('code_editor.busy'));
        try {
            return $operation();
        } finally {
            @rmdir($lock);
        }
    }

    private function replace(string $target, string $content): void
    {
        $temporary = @tempnam($this->drafts(), '.draft-');
        abort_if($temporary === false, 422, __('code_editor.write_failed'));
        try {
            abort_unless(@file_put_contents($temporary, $content) === strlen($content) && @rename($temporary, $target), 422, __('code_editor.write_failed'));
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
