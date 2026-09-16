<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\CsrfAudit;

final class CsrfAuditTest extends TestCase
{
    public function testCheckInOneHttpMethodBranchDoesNotCoverItsSibling(): void
    {
        $rows = $this->scanFixture(<<<'PHP'
            <?php
            final class Controller
            {
                public function callApi(string $action): void
                {
                    match ($action) {
                        'account.recover' => $this->handleRecovery(),
                    };
                }

                private function handleRecovery(): void
                {
                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                        Security::verifyCsrf();
                    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                        $this->resetPassword();
                    }
                }

                public function pages(): array
                {
                    $this->register(Page::api(
                        action: 'account.recover',
                        requestMethods: ['POST', 'PUT'],
                    ));

                    return [];
                }
            }
            PHP);

        self::assertSame(['POST' => true, 'PUT' => false], $rows['account.recover']['methodCoverage']);
        self::assertFalse($rows['account.recover']['covered']);
        self::assertSame('MISSING', CsrfAudit::status($rows['account.recover']));
    }

    public function testMatchDispatcherOnlyFollowsTheHandlerForTheRequestedMethod(): void
    {
        $rows = $this->scanFixture(<<<'PHP'
            <?php
            final class Controller
            {
                public function callApi(string $action): void
                {
                    match ($action) {
                        'post.item' => $this->handleItem(),
                    };
                }

                private function handleItem(): void
                {
                    match ($_SERVER['REQUEST_METHOD']) {
                        'PATCH' => $this->updatePost(),
                        'DELETE' => $this->deletePost(),
                    };
                }

                private function updatePost(): void
                {
                    Security::verifyCsrf();
                }

                private function deletePost(): void
                {
                    $this->repository->delete();
                }

                public function pages(): array
                {
                    $this->register(Page::api(
                        action: 'post.item',
                        requestMethods: ['PATCH', 'DELETE'],
                    ));

                    return [];
                }
            }
            PHP);

        self::assertSame(['DELETE' => false, 'PATCH' => true], $rows['post.item']['methodCoverage']);
        self::assertFalse($rows['post.item']['covered']);
    }

    public function testUnconditionalCheckCoversEveryDeclaredMutatingMethod(): void
    {
        $rows = $this->scanFixture(<<<'PHP'
            <?php
            final class Controller
            {
                public function callApi(string $action): void
                {
                    match ($action) {
                        'post.item' => $this->handleItem(),
                    };
                }

                private function handleItem(): void
                {
                    Security::verifyCsrf();
                    $this->saveOrDelete();
                }

                public function pages(): array
                {
                    $this->register(Page::api(
                        action: 'post.item',
                        requestMethods: ['PATCH', 'DELETE'],
                    ));

                    return [];
                }
            }
            PHP);

        self::assertSame(['DELETE' => true, 'PATCH' => true], $rows['post.item']['methodCoverage']);
        self::assertTrue($rows['post.item']['covered']);
    }

    private function scanFixture(string $source): array
    {
        $root = sys_get_temp_dir().'/csrf-audit-'.bin2hex(random_bytes(8));
        $sourceDirectory = $root.'/src';
        self::assertTrue(mkdir($sourceDirectory, 0700, true));
        self::assertNotFalse(file_put_contents($sourceDirectory.'/Controller.php', $source));

        try {
            return CsrfAudit::scan($root, ['src']);
        } finally {
            unlink($sourceDirectory.'/Controller.php');
            rmdir($sourceDirectory);
            rmdir($root);
        }
    }
}
