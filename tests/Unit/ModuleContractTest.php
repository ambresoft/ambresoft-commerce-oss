<?php

namespace Tests\Unit;

use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\ModuleGraph;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use App\Shared\Infrastructure\Modules\VersionConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleContractTest extends TestCase
{
    public function test_dependencies_register_before_dependents_and_disabled_modules_are_excluded(): void
    {
        $available = [
            'test/a' => $this->manifest('test/a', ['test/z']),
            'test/z' => $this->manifest('test/z'),
            'test/disabled' => $this->manifest('test/disabled'),
        ];
        $resolved = (new ModuleGraph)->resolve($available, ['test/a', 'test/z']);

        self::assertSame(['test/z', 'test/a'], array_column($resolved, 'name'));
        self::assertSame([], (new ModuleGraph)->resolve([], []));
    }

    #[DataProvider('invalidGraphs')]
    public function test_invalid_activation_fails_before_registration(array $dependencies, array $enabled): void
    {
        $available = [];

        foreach ($dependencies as $name => $requires) {
            $available[$name] = $this->manifest($name, $requires);
        }

        $this->expectException(InvalidArgumentException::class);
        (new ModuleGraph)->resolve($available, $enabled);
    }

    public static function invalidGraphs(): array
    {
        return [
            'unknown' => [[], ['test/missing']],
            'disabled dependency' => [['test/a' => ['test/b'], 'test/b' => []], ['test/a']],
            'missing dependency' => [['test/a' => ['test/b']], ['test/a', 'test/b']],
            'cycle' => [['test/a' => ['test/b'], 'test/b' => ['test/a']], ['test/a', 'test/b']],
            'duplicate enabled' => [['test/a' => []], ['test/a', 'test/a']],
        ];
    }

    public function test_contract_two_manifest_reads_identity_compatibility_and_contributions(): void
    {
        $manifest = ModuleManifest::fromComposer(self::composer());

        self::assertSame(['test/module', '1.2.0', 'Test module', 'Adds a test method.', 'Acme', '^1.0'], [$manifest->name, $manifest->version, $manifest->title, $manifest->description, $manifest->author, $manifest->cms]);
        self::assertSame(['Acme\\Test\\' => 'src'], $manifest->autoload);
        self::assertSame('src/Infrastructure/TestServiceProvider.php', $manifest->classFile('Acme\\Test\\Infrastructure\\TestServiceProvider'));
        self::assertNull($manifest->classFile('App\\Payment\\Infrastructure\\CashOnDelivery'), 'Classes outside its namespaces never map into the package');
        self::assertSame('test_module_', $manifest->migrationPrefix());
        self::assertTrue($manifest->compatible('1.4.2'));
        self::assertFalse($manifest->compatible('2.0.0'));
        self::assertSame(['payment-methods' => ['test' => 'Acme\\Test\\Method']], $manifest->contributes);
    }

    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_is_rejected(array $composer, string $key): void
    {
        try {
            ModuleManifest::fromComposer($composer);
            self::fail('Accepted an invalid manifest.');
        } catch (AddonException $e) {
            self::assertSame($key, $e->key);
        }
    }

    public static function invalidManifests(): array
    {
        $with = function (callable $change): array {
            $composer = self::composer();
            $change($composer);

            return $composer;
        };

        return [
            'missing name' => [$with(function (array &$c): void {
                unset($c['name']);
            }), 'addons.error_manifest_name'],
            'missing metadata' => [['name' => 'test/module'], 'addons.error_manifest_contract'],
            'contract 1' => [$with(fn (array &$c) => $c['extra']['cms-module']['contract'] = 1), 'addons.error_manifest_contract'],
            'a contract this CMS does not know' => [$with(fn (array &$c) => $c['extra']['cms-module']['contract'] = 5), 'addons.error_manifest_contract'],
            'clearing a table it does not own' => [$with(fn (array &$c) => $c['extra']['cms-module']['uninstall-clears'] = ['users']), 'addons.error_manifest_uninstall_clears'],
            'clearing as a map' => [$with(fn (array &$c) => $c['extra']['cms-module']['uninstall-clears'] = ['a' => 'test_module_x']), 'addons.error_manifest_uninstall_clears'],
            'contract as text' => [$with(fn (array &$c) => $c['extra']['cms-module']['contract'] = '3'), 'addons.error_manifest_contract'],
            'auto discovery' => [$with(fn (array &$c) => $c['extra']['laravel']['providers'] = ['X']), 'addons.error_manifest_discovery'],
            'no version' => [$with(function (array &$c): void {
                unset($c['version']);
            }), 'addons.error_manifest_version'],
            'bad version' => [$with(fn (array &$c) => $c['version'] = 'v1'), 'addons.error_manifest_version'],
            'no title' => [$with(fn (array &$c) => $c['extra']['cms-module']['title'] = ''), 'addons.error_manifest_title'],
            'bad cms constraint' => [$with(fn (array &$c) => $c['extra']['cms-module']['cms'] = '>>1'), 'addons.error_manifest_cms'],
            'core namespace' => [$with(fn (array &$c) => $c['autoload']['psr-4'] = ['App\\' => 'src/']), 'addons.error_manifest_namespace'],
            'library namespace' => [$with(fn (array &$c) => $c['autoload']['psr-4'] = ['Illuminate\\Foundation\\' => 'src/']), 'addons.error_manifest_namespace'],
            'traversing autoload path' => [$with(fn (array &$c) => $c['autoload']['psr-4'] = ['Acme\\Test\\' => '../../app']), 'addons.error_manifest_autoload'],
            'absolute autoload path' => [$with(fn (array &$c) => $c['autoload']['psr-4'] = ['Acme\\Test\\' => '/var/www']), 'addons.error_manifest_autoload'],
            'provider outside namespace' => [$with(fn (array &$c) => $c['extra']['cms-module']['provider'] = 'App\\Admin\\AdminServiceProvider'), 'addons.error_manifest_provider'],
            'dependency without constraint' => [$with(fn (array &$c) => $c['extra']['cms-module']['requires'] = ['test/other']), 'addons.error_manifest_requires'],
            'self dependency' => [$with(function (array &$c): void {
                $c['extra']['cms-module']['requires'] = ['test/module'];
                $c['require']['test/module'] = '^1.0';
            }), 'addons.error_manifest_requires'],
            'traversing migrations' => [$with(fn (array &$c) => $c['extra']['cms-module']['migrations'] = '../database/migrations'), 'addons.error_manifest_migrations'],
            'contributions as list' => [$with(fn (array &$c) => $c['extra']['cms-module']['contributes'] = ['payment-methods']), 'addons.error_manifest_contributes'],
        ];
    }

    #[DataProvider('constraints')]
    public function test_version_constraints_match_composer_semantics(string $constraint, string $version, bool $expected): void
    {
        self::assertSame($expected, VersionConstraint::satisfies($version, $constraint), $constraint.' vs '.$version);
    }

    public static function constraints(): array
    {
        return [
            ['^1.0', '1.0.0', true], ['^1.0', '1.9.3', true], ['^1.0', '2.0.0', false], ['^1.0', '0.9.0', false],
            ['^0.3.1', '0.3.9', true], ['^0.3.1', '0.4.0', false], ['^0.0.3', '0.0.4', false],
            ['~1.2', '1.9.0', true], ['~1.2', '2.0.0', false], ['~1.2.3', '1.2.9', true], ['~1.2.3', '1.3.0', false],
            ['1.2.*', '1.2.7', true], ['1.2.*', '1.3.0', false], ['1.*', '1.99.0', true], ['*', '7.0.0', true],
            ['>=1.0 <2.0', '1.5.0', true], ['>=1.0, <2.0', '2.0.0', false], ['>= 1.0', '1.0.0', true],
            ['^1.0 || ^2.0', '2.3.0', true], ['^1.0 || ^2.0', '3.0.0', false],
            ['1.2.3', '1.2.3', true], ['1.2.3', '1.2.4', false], ['!=1.0.0', '1.0.1', true],
        ];
    }

    public function test_invalid_constraints_and_versions_are_refused(): void
    {
        foreach (['', '>>1', '1.*.3', '^x', 'latest', '1.0 ||'] as $constraint) {
            self::assertFalse(VersionConstraint::valid($constraint), $constraint);
        }
        self::assertTrue(VersionConstraint::validVersion('1.2.3-beta.1'));
        foreach (['1.2', 'v1.2.3', '01.2.3', '1.2.3.4'] as $version) {
            self::assertFalse(VersionConstraint::validVersion($version), $version);
        }
    }

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        return [
            'name' => 'test/module', 'version' => '1.2.0', 'description' => 'Adds a test method.', 'authors' => [['name' => 'Acme']],
            'autoload' => ['psr-4' => ['Acme\\Test\\' => 'src/']],
            'extra' => ['cms-module' => [
                'contract' => 2, 'title' => 'Test module', 'cms' => '^1.0', 'provider' => 'Acme\\Test\\Infrastructure\\TestServiceProvider',
                'requires' => [], 'contributes' => ['payment-methods' => ['test' => 'Acme\\Test\\Method']],
            ]],
        ];
    }

    public function test_an_addon_may_name_its_own_tables_to_clear_on_uninstall(): void
    {
        $composer = self::composer();
        $composer['extra']['cms-module']['uninstall-clears'] = ['test_module_tokens'];

        self::assertSame(['test_module_tokens'], ModuleManifest::fromComposer($composer)->uninstallClears);
        self::assertSame([], ModuleManifest::fromComposer(self::composer())->uninstallClears);
    }

    public function test_contracts_two_three_and_four_are_supported(): void
    {
        foreach ([2, 3, 4] as $contract) {
            $composer = self::composer();
            $composer['extra']['cms-module']['contract'] = $contract;

            self::assertSame('test/module', ModuleManifest::fromComposer($composer)->name);
        }
    }

    /** @param list<string> $requires */
    private function manifest(string $name, array $requires = []): ModuleManifest
    {
        $prefix = 'Test'.ucfirst(str_replace(['/', '-', '.'], '', $name)).'\\';

        return ModuleManifest::fromComposer([
            'name' => $name,
            'version' => '1.0.0',
            'require' => array_fill_keys($requires, '^1.0'),
            'autoload' => ['psr-4' => [$prefix => 'src']],
            'extra' => ['cms-module' => [
                'contract' => 2,
                'title' => $name,
                'cms' => '^1.0',
                'provider' => $prefix.'Provider',
                'requires' => $requires,
            ]],
        ]);
    }
}
