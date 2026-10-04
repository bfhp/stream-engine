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

    /**
     * A manifest is valid only when every asset it lists exists in public/,
     * so the `bootstrap` theme disappears until `/assets/js/ui-bootstrap.js`
     * has been built - and while Vite empties public/assets mid-build.
     */
    private static function assertBootstrapThemeIsBuilt(): void
    {
        self::assertNotNull(
            (new ThemeCatalog(dirname(__DIR__, 2).'/views/themes', dirname(__DIR__, 2).'/public'))->find('bootstrap'),
            'The bootstrap theme is not in the catalog: run `npm run build` so its assets exist in public/assets.',
        );
    }

    public function testSiteThatNeverChoseAThemeGetsThePreferredOne(): void
    {
        self::assertBootstrapThemeIsBuilt();
        $active = $this->service()->active();

        self::assertSame(ThemeCatalog::PREFERRED_ID, $active['configuredId']);
        self::assertSame(ThemeCatalog::PREFERRED_ID, $active['id']);
        self::assertFalse($active['fallback']);
        self::assertSame(['bootstrap', 'default'], $active['chain']);
    }

    public function testExplicitDefaultSelectionIsKept(): void
    {
        self::assertSame('default', $this->service(['theme.active' => 'default'])->active()['id']);
    }

    public function testChildThemeInheritsTheParentsSavedSettingUntilItHasItsOwn(): void
    {
        self::assertBootstrapThemeIsBuilt();
        $inherited = $this->service([
            'theme.active' => 'bootstrap',
            'theme.default.color_mode' => 'light',
        ])->active();
        self::assertSame('light', $inherited['values']['color_mode']);

        $own = $this->service([
            'theme.active' => 'bootstrap',
            'theme.default.color_mode' => 'light',
            'theme.bootstrap.color_mode' => 'dark',
        ])->active();
        self::assertSame('dark', $own['values']['color_mode']);

        $invalid = $this->service([
            'theme.active' => 'bootstrap',
            'theme.default.color_mode' => 'sepia',
        ])->active();
        self::assertSame('dark', $invalid['values']['color_mode'], 'An invalid inherited value falls back to the manifest default.');
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

    public function testSystemColorModeIsStored(): void
    {
        $response = $this->service()->save('default', [
            'color_mode' => 'system',
        ]);

        self::assertSame('system', $response['theme']['values']['color_mode']);
        self::assertSame(
            ['theme.default.color_mode', 'system', 'system'],
            $this->writes[0][1],
        );
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
