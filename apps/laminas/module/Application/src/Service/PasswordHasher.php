<?php

declare(strict_types=1);

namespace Application\Service;

/**
 * パスワードのハッシュ化・照合（bcrypt）。
 * 認証ロジックから DB / セッションに依存しない形で切り出し、単体テスト可能にする。
 */
class PasswordHasher
{
    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
