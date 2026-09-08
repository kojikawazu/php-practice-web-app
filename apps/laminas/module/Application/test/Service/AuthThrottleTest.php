<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Model\LoginAttemptTable;
use Application\Service\AuthThrottle;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use PHPUnit\Framework\TestCase;

/**
 * AuthThrottle（レートリミットのポリシー層）を検証する（issue #142）。
 *
 * 保存層はモックせず SQLite in-memory の実テーブルを使う。**モックしてよいのは外部 I/O だけ**で、
 * 「何回で止まるか」「何で区切るか」はビジネスロジックそのものだから（`.claude/rules/testing.md`）。
 *
 * 検証の主眼は回数ではなく**区切り**。回数だけを見て「止まった＝OK」にすると、IP 単位で
 * 丸ごと止めてしまう実装でもテストが通り、同一 NAT 配下の利用者や CI を巻き込む設計を見逃す。
 */
class AuthThrottleTest extends TestCase
{
    private AuthThrottle $throttle;

    private LoginAttemptTable $attempts;

    protected function setUp(): void
    {
        $adapter = new Adapter([
            'driver'   => 'Pdo_Sqlite',
            'database' => ':memory:',
        ]);
        $adapter->query(
            'CREATE TABLE lam_login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_key TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );

        $this->attempts = new LoginAttemptTable(new TableGateway('lam_login_attempts', $adapter));
        $this->throttle = new AuthThrottle($this->attempts);
    }

    private function failLogin(string $username, string $ip, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttle->recordLoginFailure($username, $ip);
        }
    }

    // ---- 正常系 ----

    public function testDoesNotBlockBelowTheUserLimit(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER - 1);

        $this->assertFalse($this->throttle->isUserBlocked('alice', '10.0.0.1'));
    }

    // ---- 準正常系 ----

    public function testBlocksTheUserOnceTheLimitIsReached(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        $this->assertTrue($this->throttle->isUserBlocked('alice', '10.0.0.1'));
    }

    public function testAnotherUserFromTheSameIpIsNotBlocked(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        // 同じ IP でも別アカウントは通る。E2E がユニークなユーザー名を使う前提の根拠
        $this->assertFalse($this->throttle->isUserBlocked('bob', '10.0.0.1'));
    }

    public function testTheSameUserFromAnotherIpIsCountedSeparately(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        $this->assertFalse($this->throttle->isUserBlocked('alice', '10.0.0.2'));
    }

    public function testUsernameCaseIsIgnored(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        // 大文字にするだけで別キー扱いになると、総当たりが素通りする
        $this->assertTrue($this->throttle->isUserBlocked('ALICE', '10.0.0.1'));
    }

    public function testIpLimitBlocksEvenWhenEachUserIsBelowItsLimit(): void
    {
        // 1 アカウントあたりは上限未満でも、IP 全体では上限に達する
        for ($i = 0; $i < AuthThrottle::PER_IP; $i++) {
            $this->throttle->recordIpAttempt('10.0.0.1');
        }

        $this->assertTrue($this->throttle->isIpBlocked('10.0.0.1'));
        $this->assertFalse($this->throttle->isUserBlocked('alice', '10.0.0.1'));
    }

    public function testIpLimitIsScopedToTheIp(): void
    {
        for ($i = 0; $i < AuthThrottle::PER_IP; $i++) {
            $this->throttle->recordIpAttempt('10.0.0.1');
        }

        $this->assertFalse($this->throttle->isIpBlocked('10.0.0.2'));
    }

    public function testLoginFailureCountsTowardBothKeys(): void
    {
        $this->failLogin('alice', '10.0.0.1', 3);

        $this->assertSame(3, $this->attempts->countRecent('login|alice|10.0.0.1', AuthThrottle::WINDOW_SECONDS));
        $this->assertSame(3, $this->attempts->countRecent('ip|10.0.0.1', AuthThrottle::WINDOW_SECONDS));
    }

    // ---- 異常系（成功時の後始末） ----

    public function testSuccessfulLoginClearsOnlyTheUserKey(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        $this->throttle->clearLoginAttempts('alice', '10.0.0.1');

        $this->assertFalse($this->throttle->isUserBlocked('alice', '10.0.0.1'));
        // IP 側は消さない。消すと「1 回成功させるたびに IP の予算が戻る」抜け道になる
        $this->assertSame(
            AuthThrottle::LOGIN_PER_USER,
            $this->attempts->countRecent('ip|10.0.0.1', AuthThrottle::WINDOW_SECONDS)
        );
    }

    public function testClearingOneUserDoesNotAffectAnother(): void
    {
        $this->failLogin('alice', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);
        $this->failLogin('bob', '10.0.0.1', AuthThrottle::LOGIN_PER_USER);

        $this->throttle->clearLoginAttempts('alice', '10.0.0.1');

        $this->assertTrue($this->throttle->isUserBlocked('bob', '10.0.0.1'));
    }
}
