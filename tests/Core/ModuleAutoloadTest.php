<?php

declare(strict_types=1);

namespace Tests\Core;

use Composer\Autoload\ClassLoader;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\User;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ModuleAutoloadTest extends TestCase
{
    private string $root;
    private ClassLoader $autoload;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/external-modules-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/migrations', 0777, true);
        $this->root = realpath($this->root);
        $this->composer(['MySite\\Modules\\' => 'modules/']);
        mkdir($this->root.'/vendor');
        $this->autoload = new ClassLoader($this->root.'/vendor');
        $this->autoload->addPsr4('MySite\\Modules\\', $this->root.'/modules');
        $this->autoload->register(true);
    }

    protected function tearDown(): void
    {
        $this->autoload->unregister();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function composer(array $mappings): void
    {
        file_put_contents($this->root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => $mappings],
        ], JSON_THROW_ON_ERROR));
    }

    private function module(string $name, string $action = ''): string
    {
        $dir = $this->root.'/modules/'.$name;
        mkdir($dir, 0777, true);
        $actions = $action === '' ? '[]' : var_export([$action => 'Test page'], true);
        file_put_contents($dir.'/'.$name.'Controller.php', '<?php namespace MySite\\Modules\\'.$name.'; '
            .'final class '.$name.'Controller extends \\StreamEngine\\Core\\AbstractController {'
            .'public static function pageActions(): array { return '.$actions.'; }}');

        $this->autoload->addClassMap(['MySite\\Modules\\'.$name.'\\'.$name.'Controller' => $dir.'/'.$name.'Controller.php']);

        return $dir;
    }

    public function testExternalModuleWorksAlongsideBuiltInsInFactoryAndTwig(): void
    {
        $dir = $this->module('Notes', 'notes.show');
        mkdir($dir.'/views');
        $modules = new ModuleRegistry();
        self::assertSame('Notes', $modules->idForAction('notes.show'));
        self::assertNotNull($modules->controllerClassFor('Forums'));

        file_put_contents($dir.'/views/notes.twig', 'External {{ value }}');
        $twig = new Environment(new FilesystemLoader($modules->viewsPaths()));
        self::assertSame('External works', $twig->render('notes.twig', ['value' => 'works']));

        $context = new RequestContext(new User(0, ''), new DateTimeZone('UTC'));
        $controller = (new ControllerFactory($modules, $this->createStub(PdoDatabase::class)))
            ->create('Notes', $context);
        self::assertInstanceOf('MySite\\Modules\\Notes\\NotesController', $controller);
    }

    public function testMissingOrEmptyExternalDirectoryPreservesBuiltIns(): void
    {
        $expected = new ModuleRegistry();
        self::assertSame($expected->entries(), (new ModuleRegistry())->entries());
        mkdir($this->root.'/modules');
        self::assertSame($expected->entries(), (new ModuleRegistry())->entries());
    }

    public function testModuleWithoutOptionalResourcesCanBeRemoved(): void
    {
        $dir = $this->module('Minimal');
        $modules = new ModuleRegistry();
        self::assertNotNull($modules->controllerClassFor('Minimal'));
        self::assertNotContains($dir.'/views', $modules->viewsPaths());
        unlink($dir.'/MinimalController.php');
        rmdir($dir);
        $this->autoload->unregister();
        $this->autoload = new ClassLoader($this->root.'/vendor');
        $this->autoload->register(true);
        self::assertNull((new ModuleRegistry())->controllerClassFor('Minimal'));
    }

    public function testExternalModuleCannotReplaceBuiltInId(): void
    {
        $this->module('Forums');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate module id 'Forums'");
        new ModuleRegistry();
    }

    public function testExternalModulesCannotShareActions(): void
    {
        $this->module('First', 'shared.show');
        $this->module('Second', 'shared.show');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate page action 'shared.show'");
        new ModuleRegistry();
    }

    public function testDirectoryWithoutControllerIsIgnored(): void
    {
        mkdir($this->root.'/modules/Broken', 0777, true);
        self::assertNull((new ModuleRegistry())->controllerClassFor('Broken'));
    }

    public function testAbstractControllerIsNotRegistered(): void
    {
        $dir = $this->module('Incomplete');
        $file = $dir.'/IncompleteController.php';
        file_put_contents($file, str_replace('final class', 'abstract class', file_get_contents($file)));
        self::assertNull((new ModuleRegistry())->controllerClassFor('Incomplete'));
    }

    public function testNamespaceComesFromClassmap(): void
    {
        $dir = $this->module('Custom');
        $file = $dir.'/CustomController.php';
        file_put_contents($file, str_replace('MySite\\Modules', 'OtherVendor\\Features', file_get_contents($file)));
        $this->autoload->unregister();
        $this->autoload = new ClassLoader($this->root.'/vendor');
        $this->autoload->addClassMap(['OtherVendor\\Features\\Custom\\CustomController' => $file]);
        $this->autoload->register(true);
        $this->composer(['OtherVendor\\Features\\' => ['elsewhere/', './modules/']]);
        self::assertSame(
            'OtherVendor\\Features\\Custom\\CustomController',
            (new ModuleRegistry())->controllerClassFor('Custom'),
        );
    }

    public function testDiscoveryUsesLoadedMappingsWithoutReadingComposerJson(): void
    {
        $this->module('JsonProbe');
        file_put_contents($this->root.'/composer.json', '{broken');
        self::assertNotNull((new ModuleRegistry())->controllerClassFor('JsonProbe'));
    }

    public function testVendorModuleIsDiscovered(): void
    {
        $directory = $this->root.'/vendor/example/package/src/Packaged';
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/PackagedController.php', '<?php namespace VendorProbe\\Features\\Packaged; '
            .'final class PackagedController extends \\StreamEngine\\Core\\AbstractController {}');
        $this->autoload->addClassMap(['VendorProbe\\Features\\Packaged\\PackagedController' => $directory.'/PackagedController.php']);
        self::assertSame('VendorProbe\\Features\\Packaged\\PackagedController', (new ModuleRegistry())->controllerClassFor('Packaged'));
    }

    public function testUnloadableOptionalVendorClassIsIgnored(): void
    {
        $directory = $this->root.'/vendor/example/optional/src';
        mkdir($directory, 0777, true);
        $file = $directory.'/IntegrationTestCase.php';
        file_put_contents($file, '<?php namespace OptionalProbe; '
            .'abstract class IntegrationTestCase extends \\MissingOptionalDependency\\TestCase {}');
        $this->autoload->addClassMap(['OptionalProbe\\IntegrationTestCase' => $file]);

        $modules = new ModuleRegistry();

        self::assertNotNull($modules->controllerClassFor('Forums'));
        self::assertNotContains('OptionalProbe\\IntegrationTestCase', $modules->controllerClasses());
    }

    public function testBuiltInControllersAndResourcesAreDiscovered(): void
    {
        $modules = new ModuleRegistry();
        foreach (glob(__DIR__.'/../../src/Modules/*/*Controller.php') as $file) {
            $name = basename(dirname($file));
            self::assertSame('StreamEngine\\Modules\\'.$name.'\\'.$name.'Controller', $modules->controllerClassFor($name));
            if (is_dir(dirname($file).'/views')) {
                self::assertContains(realpath(dirname($file).'/views'), $modules->viewsPaths());
            }
        }
    }

    public function testClassmapOnlyModuleIsDiscovered(): void
    {
        $directory = $this->root.'/vendor/example/mapped/deep/OnlyMapped';
        mkdir($directory, 0777, true);
        $file = $directory.'/implementation.php';
        file_put_contents($file, '<?php namespace ClassmapProbe; '
            .'final class OnlyMapped extends \\StreamEngine\\Core\\AbstractController {}');
        $class = 'ClassmapProbe\\OnlyMapped';
        $this->autoload->addClassMap([$class => $file]);
        $this->autoload->setClassMapAuthoritative(true);
        self::assertSame($class, (new ModuleRegistry())->controllerClassFor('OnlyMapped'));
    }

    public function testPsr4DirectoryIsNotScannedWithoutClassmapEntry(): void
    {
        $directory = $this->root.'/modules/Unmapped';
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/UnmappedController.php', '<?php namespace MySite\\Modules\\Unmapped; '
            .'final class UnmappedController extends \\StreamEngine\\Core\\AbstractController {}');
        self::assertNull((new ModuleRegistry())->controllerClassFor('Unmapped'));
    }

    public function testSameControllerInTwoClassmapsIsRegisteredOnce(): void
    {
        $directory = $this->module('SharedMap');
        $second = new ClassLoader($this->root.'/second-vendor');
        $second->addClassMap(['MySite\\Modules\\SharedMap\\SharedMapController' => $directory.'/SharedMapController.php']);
        $second->register(true);
        try {
            $classes = (new ModuleRegistry())->controllerClasses();
            self::assertSame(1, count(array_filter($classes, static fn (string $class) => $class === 'MySite\\Modules\\SharedMap\\SharedMapController')));
        } finally {
            $second->unregister();
        }
    }

    public function testMigrationCliCombinesOnlySiteAndEngineHistory(): void
    {
        mkdir($this->root.'/bin');
        $one = $this->module('One');
        $two = $this->module('Two');
        mkdir($one.'/migrations');
        mkdir($two.'/migrations');
        copy(__DIR__.'/../../bin/migrate.php', $this->root.'/bin/migrate.php');
        file_put_contents($this->root.'/.env', '');
        file_put_contents($this->root.'/vendor/autoload.php', '<?php $loader = require '
            .var_export(realpath(__DIR__.'/../../vendor/autoload.php'), true).'; '
            .'$siteLoader = new \Composer\Autoload\ClassLoader(__DIR__); $siteLoader->register(true); '
            .'$loader->addPsr4("MySite\\\\Modules\\\\", __DIR__."/../modules", true);');
        $directories = [$one.'/migrations', $two.'/migrations', $this->root.'/migrations'];
        foreach ($directories as $index => $path) {
            file_put_contents($path.'/2099010100000'.$index.'_probe.sql', 'SELECT 1;');
        }
        $pending = 1 + count(glob(__DIR__.'/../../migrations/*.sql'));
        self::assertStringContainsString('Pending: '.$pending, $this->cli(['status']));
        $this->cli(['baseline', '20990101000002_probe']);
        self::assertStringContainsString('Pending: 0', $this->cli(['status']));
        $history = json_decode(file_get_contents($this->root.'/storage/migrations.json'), true);
        self::assertCount($pending, $history['applied']);
        self::assertDirectoryDoesNotExist($this->root.'/storage/module-migrations');
        self::assertStringContainsString('Unknown option', $this->cli(['status', '--module=One'], 1));
        self::assertStringContainsString('Created ', $this->cli(['make', 'more notes']));
        self::assertCount(1, glob($this->root.'/migrations/*_more_notes.sql'));

    }

    private function cli(array $arguments, int $expectedExit = 0): string
    {
        $process = proc_open(
            [PHP_BINARY, $this->root.'/bin/migrate.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($expectedExit, proc_close($process), $output);

        return $output;
    }
}
