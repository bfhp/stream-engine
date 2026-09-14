<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\User;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class AuthenticatedNavTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $this->twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
    }

    public function testMessagesButtonUsesResolvedInboxUrl(): void
    {
        $html = $this->render(messagesUrl: '/mail/');

        $this->assertStringContainsString('href="/mail/"', $html);
        $this->assertStringContainsString('bi-envelope', $html);
    }

    public function testMessagesButtonIsNotRenderedWithoutInboxPage(): void
    {
        $html = $this->render(messagesUrl: null);

        $this->assertStringNotContainsString('bi-envelope', $html);
        $this->assertStringNotContainsString('view.nav.messages', $html);
    }

    public function testProfileButtonUsesResolvedProfileUrl(): void
    {
        $html = $this->render(profileUrl: '/account/');

        $this->assertStringContainsString('id="menu-user"', $html);
        $this->assertStringContainsString('href="/account/"', $html);
        $this->assertStringContainsString('src="/avatar.svg"', $html);
    }

    public function testProfileButtonIsNotRenderedWithoutProfilePage(): void
    {
        $html = $this->render(profileUrl: null);

        $this->assertStringNotContainsString('id="menu-user"', $html);
        $this->assertStringNotContainsString('src="/avatar.svg"', $html);
        $this->assertStringContainsString('id="menu-user-toggle"', $html);
    }

    private function render(?string $messagesUrl = '/messages/', ?string $profileUrl = '/profile/'): string
    {
        return $this->twig->render('components/nav/user/authenticated.twig', [
            'messagesUrl' => $messagesUrl,
            'profileUrl' => $profileUrl,
            'user' => new User(id: 42, email: '', nick: 'User', avatarUrl: '/avatar.svg'),
            'userMenu' => [],
        ]);
    }
}
