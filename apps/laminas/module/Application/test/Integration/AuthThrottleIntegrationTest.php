<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Controller\AuthController;
use Application\Model\LoginAttemptTable;
use Application\Service\AuthThrottle;
use Laminas\Db\TableGateway\TableGateway;

/**
 * レートリミットがコントローラ経由で効くことを検証する（issue #142）。
 *
 * 単体（{@see \ApplicationTest\Service\AuthThrottleTest}）が「何回で・何で区切って止まるか」を
 * 見るのに対し、ここは**ルーティング→コントローラ→Table→実 DB を横断して 429 が返るか**を見る。
 * 上限に到達させるまで dispatch を繰り返すと 1 ケースで 6 往復することになるため、
 * 試行記録は本物の {@see AuthThrottle} で先に積んでから 1 回だけ dispatch する
 * （キーの作り方をテスト側で書き写さないため、モックではなく本物を使う）。
 */
class AuthThrottleIntegrationTest extends AbstractIntegrationTestCase
{
    /** dispatch 時に REMOTE_ADDR として読まれる値。キーを決定的にするため明示する */
    private const CLIENT_IP = '10.0.0.1';

    private AuthThrottle $throttle;

    private LoginAttemptTable $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;

        $this->attempts = new LoginAttemptTable(new TableGateway('lam_login_attempts', $this->adapter));
        $this->throttle = new AuthThrottle($this->attempts);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        parent::tearDown();
    }

    private function failLogin(string $username, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttle->recordLoginFailure($username, self::CLIENT_IP);
        }
    }

    // ---- 正常系 ----

    public function testLoginIsNotBlockedBelowTheLimit(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));
        $this->failLogin('alice', AuthThrottle::LOGIN_PER_USER - 1);

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertRedirectTo('/tasks');
    }

    public function testSuccessfulLoginClearsTheUserAttempts(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));
        $this->failLogin('alice', AuthThrottle::LOGIN_PER_USER - 1);

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertSame(
            0,
            $this->attempts->countRecent('login|alice|' . self::CLIENT_IP, AuthThrottle::WINDOW_SECONDS)
        );
    }

    // ---- 準正常系（上限超過） ----

    public function testLoginReturns429WhenTheUserLimitIsReached(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));
        $this->failLogin('alice', AuthThrottle::LOGIN_PER_USER);

        $this->prepareServices(null);
        // 正しいパスワードでも通さない。ここが総当たり耐性の実体
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCode(429);
        $this->assertNotRedirect();
        $this->assertControllerName(AuthController::class);
    }

    public function testAnotherUserIsNotBlockedFromTheSameIp(): void
    {
        $this->userTable->create('bob', $this->hasher->hash('password123'));
        $this->failLogin('alice', AuthThrottle::LOGIN_PER_USER);

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'bob',
            'password' => 'password123',
        ]));

        $this->assertRedirectTo('/tasks');
    }

    public function testRegisterReturns429WhenTheIpLimitIsReached(): void
    {
        for ($i = 0; $i < AuthThrottle::PER_IP; $i++) {
            $this->throttle->recordIpAttempt(self::CLIENT_IP);
        }

        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username'         => 'charlie',
            'password'         => 'password123',
            'password_confirm' => 'password123',
        ]));

        $this->assertResponseStatusCode(429);
        $this->assertNull($this->userTable->findByUsername('charlie'), '上限超過なのにユーザーが作られている');
    }

    public function testLoginReturns429WhenTheIpLimitIsReached(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));
        for ($i = 0; $i < AuthThrottle::PER_IP; $i++) {
            $this->throttle->recordIpAttempt(self::CLIENT_IP);
        }

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCode(429);
    }

    // ---- 異常系（記録が漏れると無制限になる） ----

    public function testFailedLoginIsRecorded(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'wrongpass',
        ]));

        $this->assertResponseStatusCode(200);
        $this->assertSame(
            1,
            $this->attempts->countRecent('login|alice|' . self::CLIENT_IP, AuthThrottle::WINDOW_SECONDS),
            '失敗が記録されていない（記録が漏れると上限に到達せず無制限になる）'
        );
    }

    public function testInvalidInputIsCountedAgainstTheIp(): void
    {
        $this->prepareServices(null);
        // 入力不備でも IP 単位では数える。数えないと壊れた入力を投げ続けて回数を消費できる
        $this->dispatch('/login', 'POST', $this->withCsrf(['username' => '', 'password' => '']));

        $this->assertResponseStatusCode(200);
        $this->assertSame(1, $this->attempts->countRecent('ip|' . self::CLIENT_IP, AuthThrottle::WINDOW_SECONDS));
    }
}
