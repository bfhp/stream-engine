<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\ThemeCatalog;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\ThemeService;

final class ThemeServiceTest extends TestCase
{
    /** @var list<array{string, list<string>}> */
    private array $writes = [];

    private function service(array $settings = []): ThemeService
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn (string $value, string $key): array => [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => 1,
            ],
            $settings,
            array_keys($settings),
        ));
        $db->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            $this->writes[] = [$sql, $params];

            return 1;
        });
        $repository = new SettingsRepository($db);

        return new ThemeService(
            new ThemeCatalog(dirname(__DIR__, 2).'/views/themes', dirname(__DIR__, 2).'/public'),
            new SettingsService($repository),
            $repository,
        );
    }

    public function testMissingConfiguredThemeFallsBackWithoutLosingItsIdentity(): void
    {
        $active = $this->service(['theme.active' => 'removed-theme'])->active();

        self::assertSame('default', $active['id']);
        self::assertSame('removed-theme', $active['configuredId']);
        self::assertTrue($active['fallback']);
    }

    public function testValidatedSettingsAreStoredInTheThemeNamespace(): void
    {
        $response = $this->service()->save('default', [
            'color_mode' => 'light',
        ]);

        self::assertSame('default', $response['activeId']);
        self::assertSame([
            ['theme.default.color_mode', 'light', 'light'],
            ['theme.active', 'default', 'default'],
        ], array_column($this->writes, 1));
    }

    public function testInvalidSchemaValueIsRejectedBeforeAnyWrite(): void
    {
        try {
            $this->service()->save('default', ['color_mode' => 'sepia']);
            self::fail('invalid theme value should be refused');
        } catch (ValidationException) {
            self::assertSame([], $this->writes);
        }
    }
}
