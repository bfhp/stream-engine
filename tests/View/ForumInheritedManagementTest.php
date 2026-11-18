<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ForumInheritedManagementTest extends TestCase
{
    public function testInheritedManagementLinksToTheCategoryAndDisablesMemberSearch(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/src/Modules/Forums/views'));
        $twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $html = $twig->render('components/forums/manage.twig', [
            'feed' => ['id' => 101, 'parentId' => 100, 'title' => 'Club', 'description' => '', 'visibility' => 'members'],
            'managementApiUrl' => '/api/v1/forums/manage/101', 'managementInherited' => true,
            'managementOwnVisibility' => 'private', 'managementCategoryUrl' => '/forums/category/?manage=1',
            'managementParents' => [['id' => 100, 'title' => 'Category', 'visibility' => 'members']],
        ]);
        self::assertStringContainsString('js.forums.inherited_access', $html);
        self::assertStringContainsString('href="/forums/category/?manage=1"', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        self::assertStringContainsString('data-own-visibility="private"', $html);
        self::assertStringContainsString('data-visibility="members" selected', $html);
        self::assertMatchesRegularExpression('/name="query"[^>]*disabled/', $html);
    }
}
