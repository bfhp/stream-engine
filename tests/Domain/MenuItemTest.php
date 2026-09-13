<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\MenuItem;
use StreamEngine\Service\AccessService;

/**
 * Four one-line predicates that the menu template branches on. They are worth
 * pinning not for their arithmetic but for their *partition*: the template
 * renders an item exactly once per branch, so a type that satisfies none of
 * them disappears from the menu, and one that satisfies two renders twice.
 */
final class MenuItemTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'internal',
            'page_id' => 3,
            'url' => null,
            'action' => null,
            'label' => 'Форум',
            'access_rule' => 'public',
            'sort_order' => 10,
        ];
    }

    private static function ofType(string $type): MenuItem
    {
        return MenuItem::fromRow(self::row(['type' => $type]));
    }

    /**
     * @param list<string> $expected which predicates answer true
     */
    #[DataProvider('typeProvider')]
    public function testEachTypeSatisfiesExactlyItsOwnPredicates(string $type, array $expected): void
    {
        $item = self::ofType($type);

        $answered = array_keys(array_filter([
            'link' => $item->isLink(),
            'external' => $item->isExternal(),
            'action' => $item->isAction(),
            'divider' => $item->isDivider(),
        ]));

        $this->assertSame(
            $expected,
            $answered,
            'type '.$type.' answered the wrong set of predicates'
        );
    }

    /** @return array<string, array{string, list<string>}> */
    public static function typeProvider(): array
    {
        return [
            // An external item is a link *and* external - the only overlap,
            // and a deliberate one: the template asks isLink() to decide
            // whether to render an <a> and isExternal() to decide rel/target.
            'external' => ['external', ['link', 'external']],
            'internal' => ['internal', ['link']],
            'dynamic' => ['dynamic', ['link']],
            'action' => ['action', ['action']],
            'divider' => ['divider', ['divider']],
            // Nothing claims an unknown type, so it renders as nothing rather
            // than as a broken link. Pinned because that silence is the
            // failure mode: a typo in the menu table makes an item vanish.
            'an unknown type' => ['heading', []],
            'an empty type' => ['', []],
        ];
    }

    /* ===============================
       fromRow
    =============================== */

    /**
     * The nullable columns stay null instead of becoming 0, which is what the
     * template checks to decide whether an item has a parent or a target page.
     * `(int) null` is 0, and 0 is a plausible-looking id.
     */
    public function testNullableIdsSurviveAsNull(): void
    {
        $item = MenuItem::fromRow(self::row(['parent' => null, 'page_id' => null]));

        $this->assertNull($item->parentId);
        $this->assertNull($item->pageId);
    }

    public function testNumericColumnsArriveAsIntegers(): void
    {
        // Numeric columns are cast, while the stored audience remains a string.
        $item = MenuItem::fromRow(self::row([
            'id' => '7',
            'parent' => '2',
            'page_id' => '3',
            'access_rule' => 'admin',
            'sort_order' => '10',
        ]));

        $this->assertSame(7, $item->id);
        $this->assertSame(2, $item->parentId);
        $this->assertSame(3, $item->pageId);
        $this->assertSame(AccessService::ACCESS_ADMIN, $item->accessRule);
        $this->assertSame(10, $item->sortOrder);
    }

    /**
     * A parent id of 0 is not the same as no parent - it would be a real id.
     * Kept distinct here because the null check is `!== null`, not falsy.
     */
    public function testAZeroParentIdIsAnIdRatherThanAbsence(): void
    {
        $this->assertSame(0, MenuItem::fromRow(self::row(['parent' => 0]))->parentId);
    }

    public function testAFreshItemIsInactiveAndChildless(): void
    {
        $item = MenuItem::fromRow(self::row());

        // Both are runtime state MenuService fills in later; starting them
        // anywhere else would leave every item highlighted.
        $this->assertFalse($item->active);
        $this->assertSame([], $item->children);
    }

    public function testAMissingLabelIsNullRatherThanAbsent(): void
    {
        $row = self::row();
        unset($row['label']);

        $this->assertNull(MenuItem::fromRow($row)->label);
    }
}
