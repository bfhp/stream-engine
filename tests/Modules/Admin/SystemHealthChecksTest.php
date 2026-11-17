<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Modules\Admin\AdminController;
use ReflectionClass;

final class SystemHealthChecksTest extends TestCase
{
    private function checks(Config $config, PdoDatabase $db, TranslationManager $tm, string $root): AdminController
    {
        $controller = (new ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'config' => $config, 'tm' => $tm] as $name => $value) {
            (new \ReflectionProperty(AdminController::class, $name))->setValue($controller, $value);
        }
        return $controller;
    }

    private function probe(AdminController $controller, string $id): array
    {
        return (new \ReflectionMethod(AdminController::class, 'healthProbe'))->invoke($controller, $id);
    }

    public function testLazyTwigDirectoryCanBeCreatedWithoutLeavingProbeDirectories(): void
    {
        $root = sys_get_temp_dir().'/health-twig-'.bin2hex(random_bytes(8));
        mkdir($root);
        $checks = $this->checks(
            new Config(['TWIG_CACHE_DIR' => $root.'/nested/twig']),
            $this->createStub(PdoDatabase::class),
            new TranslationManager('en'),
            $root,
        );
        try {
            self::assertSame('ok', $this->probe($checks, 'twig')['status']);
            self::assertSame([], array_values(array_diff(scandir($root), ['.', '..'])));
            self::assertStringContainsString('Minimum free space', $this->probe($checks, 'disk')['message']);
        } finally {
            rmdir($root);
        }
    }

    public function testFileProbeUsesConfiguredDirectoryAndLeavesNoFiles(): void
    {
        $root = sys_get_temp_dir().'/health-test-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/uploads');
        $checks = $this->checks(
            new Config(['UPLOADS_DIR' => $root.'/uploads']),
            $this->createStub(PdoDatabase::class),
            new TranslationManager('en'),
            $root,
        );
        try {
            self::assertSame('ok', $this->probe($checks, 'uploads')['status']);
            self::assertSame([], array_values(array_diff(scandir($root.'/uploads'), ['.', '..'])));
            rmdir($root.'/uploads');
            self::assertSame('error', $this->probe($checks, 'uploads')['status']);
            self::assertDirectoryDoesNotExist($root.'/uploads');
        } finally {
            @rmdir($root.'/uploads');
            rmdir($root);
        }
    }

    public function testMissingSmtpCredentialsDoNotAttemptConnection(): void
    {
        $checks = $this->checks(
            new Config(['SMTP_HOST' => 'invalid.example.test']),
            $this->createStub(PdoDatabase::class),
            new TranslationManager('en'),
            dirname(__DIR__, 2),
        );
        self::assertSame('unconfigured', $this->probe($checks, 'smtp')['status']);
    }

    public function testDatabaseProbeRequiresSuccessfulQuery(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects(self::once())->method('fetchOne')->with('SELECT 1 AS health')->willReturn(['health' => 1]);
        $checks = $this->checks(new Config([]), $db, new TranslationManager('en'), dirname(__DIR__, 2));
        self::assertSame('ok', $this->probe($checks, 'database')['status']);
    }

    public function testEveryCheckHasLabelsAndExceptionMessagesInSupportedPrimaryLocales(): void
    {
        foreach (['en', 'ru'] as $locale) {
            $tm = new TranslationManager($locale);
            foreach ((new ReflectionClass(AdminController::class))->getConstant('HEALTH_CHECKS') as $id) {
                self::assertNotSame('health.check.'.$id, $tm->trans('health.check.'.$id));
                $key = in_array($id, ['storage', 'uploads', 'temporary', 'twig'], true)
                    ? 'health.message.directory_failed' : 'health.message.'.$id.'_failed';
                self::assertNotSame($key, $tm->trans($key));
            }
        }
    }
}
