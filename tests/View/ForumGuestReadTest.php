<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ForumGuestReadTest extends TestCase
{
    #[DataProvider('templates')]
    public function testGuestHasAnEnabledMarkAllReadButton(string $theme, string $template): void
    {
        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader([
            $root.'/views/themes/'.$theme,
            $root.'/views/themes/default',
            $root.'/src/Modules/Forums/views',
        ]);
        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('trans_choice', static fn (string $key): string => $key));
        $html = $twig->load('modules/forums/'.$template.'.twig')->renderBlock('content', [
            'user' => ['isGuest' => true],
            'feed' => ['id' => 70],
        ]);

        self::assertMatchesRegularExpression('/<button\\b[^>]*data-forum-mark-all-read[^>]*>/', $html);
        preg_match('/<button\\b[^>]*data-forum-mark-all-read[^>]*>/', $html, $button);
        self::assertStringNotContainsString('disabled', $button[0]);
        if ($template === 'forums.topic-list') {
            self::assertStringContainsString('data-forum-id="70"', $button[0]);
            self::assertStringContainsString('#authFormModal', $html);
        }
    }

    public static function templates(): iterable
    {
        foreach (['default', 'bootstrap'] as $theme) {
            foreach (['forums.list', 'forums.topic-list'] as $template) {
                yield $theme.' '.$template => [$theme, $template];
            }
        }
    }
}
