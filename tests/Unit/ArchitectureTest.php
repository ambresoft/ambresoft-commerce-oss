<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ArchitectureTest extends TestCase
{
    public function test_php_layers_keep_inward_application_dependencies_and_pascal_case_directories(): void
    {
        $root = dirname(__DIR__, 2).'/app/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root));
            $directories = explode('/', dirname($relative));
            foreach ($directories as $directory) {
                self::assertMatchesRegularExpression('/^[A-Z][A-Za-z0-9]*$/', $directory, $relative);
            }

            $context = $directories[0];
            $layer = $directories[1] ?? '';
            $allowed = match ($layer) {
                'Domain' => ['App\\'.$context.'\\Domain\\', 'App\\Shared\\Domain\\'],
                'Application' => ['App\\'.$context.'\\Domain\\', 'App\\'.$context.'\\Application\\', 'App\\Shared\\Domain\\', 'App\\Shared\\Application\\'],
                default => null,
            };
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_STRING && $allowed !== null) {
                    self::assertNotContains(strtolower($token[1]), ['app', 'config', 'auth', 'event', 'dispatch', 'request', 'response', 'route', 'session', 'collect', 'env', 'now', 'logger', 'dd', 'resolve'], $relative.' must not call framework helpers.');
                }
                if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $name = ltrim($token[1], '\\');
                if ($allowed !== null && str_contains($name, '\\')) {
                    self::assertNotEmpty(array_filter($allowed, fn (string $prefix): bool => str_starts_with($name, $prefix)), $relative.' cannot depend on '.$name);
                } elseif ($layer === 'Infrastructure' && str_starts_with($name, 'App\\')) {
                    self::assertFalse(str_contains($name, '\\Presentation\\') || str_starts_with($name, 'App\\Admin\\') || str_starts_with($name, 'App\\Http\\'), $relative.' cannot depend on presentation '.$name);
                }
            }
        }
    }
}
