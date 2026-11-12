<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Admin;

use StreamEngine\Core\Exceptions\ValidationException;

final readonly class DashboardLayoutCookieStore
{
    public function cookieName(int $userId): string
    {
        return 'admin_dashboard_layout_'.$userId;
    }

    /** @return array{version:int,items:list<array<string,mixed>>}|null */
    public function find(int $userId): ?array
    {
        $value = $_COOKIE[$this->cookieName($userId)] ?? null;
        if (! is_string($value) || strlen($value) > 3800) {
            return null;
        }
        $decoded = base64_decode($value, true);
        $layout = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($layout) || ! is_int($layout['version'] ?? null)
            || ! is_array($layout['items'] ?? null) || ! array_is_list($layout['items'])) {
            return null;
        }

        return ['version' => $layout['version'], 'items' => $layout['items']];
    }

    /** @param list<array{id:string,size:string,position:int}> $items */
    public function save(int $userId, int $version, array $items): void
    {
        $value = base64_encode(json_encode(compact('version', 'items'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (strlen($value) > 3800) {
            throw new ValidationException('Dashboard layout exceeds the cookie size limit');
        }
        $this->write($userId, $value, time() + 31536000);
        $_COOKIE[$this->cookieName($userId)] = $value;
    }

    public function delete(int $userId): void
    {
        $this->write($userId, '', time() - 3600);
        unset($_COOKIE[$this->cookieName($userId)]);
    }

    private function write(int $userId, string $value, int $expires): void
    {
        setcookie($this->cookieName($userId), $value, [
            'expires' => $expires,
            'path' => '/api/v1/admin/dashboard',
            'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
