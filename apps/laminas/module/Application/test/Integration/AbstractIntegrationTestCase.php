<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Model\Task;
use Application\Model\TaskTable;
use Application\Model\UserTable;
use Application\Service\AuthSessionInterface;
use Application\Service\CsrfGuard;
use Application\Service\PasswordHasher;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Storage\NonPersistent;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Session\Config\StandardConfig;
use Laminas\Session\SessionManager;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\Storage\ArrayStorage;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

/**
 * 統合テスト（IT）の共通基盤。
 *
 * 実 MVC アプリを起動し、ルーティング → コントローラ → 認証 → TableGateway → DB を
 * 通しで検証する。MySQL・実セッションには依存せず、bootstrap 後に ServiceManager の
 * DB アダプタを SQLite in-memory へ、AuthenticationService を NonPersistent ストレージへ
 * 差し替える（既存 TaskTableTest と同じ隔離方針。認証の識別子は直接注入する）。
 */
abstract class AbstractIntegrationTestCase extends AbstractHttpControllerTestCase
{
    protected Adapter $adapter;

    /** テスト側から DB 状態を検証・シードするための Table 層（本番と同一アダプタを共有） */
    protected TaskTable $taskTable;

    protected UserTable $userTable;

    protected PasswordHasher $hasher;

    /** CSRF 検証の実体（テスト用セッションを注入したもの） */
    protected CsrfGuard $csrf;

    /** 認証遷移時のセッション操作の記録（実セッションを張らずに呼び出しを検証する） */
    protected RecordingAuthSession $authSession;

    protected function setUp(): void
    {
        $this->setApplicationConfig(ArrayUtils::merge(
            include __DIR__ . '/../../../../config/application.config.php',
            // テストでは設定キャッシュを無効化し、毎回まっさらな状態で起動する
            ['module_listener_options' => ['config_cache_enabled' => false]]
        ));

        parent::setUp();

        $this->adapter = new Adapter([
            'driver'   => 'Pdo_Sqlite',
            'database' => ':memory:',
        ]);
        $this->createSchema();

        $resultSetPrototype = new ResultSet();
        /** @psalm-suppress InvalidArgument Task は exchangeArray を持ちプロトタイプとして機能する */
        $resultSetPrototype->setArrayObjectPrototype(new Task());
        $this->taskTable = new TaskTable(new TableGateway('lam_tasks', $this->adapter, null, $resultSetPrototype));
        $this->userTable = new UserTable(new TableGateway('lam_users', $this->adapter));
        $this->hasher = new PasswordHasher();
    }

    /** lam_users / lam_tasks の SQLite 相当スキーマ（MySQL 版と同じ列構成）を作成する */
    private function createSchema(): void
    {
        $this->adapter->query(
            'CREATE TABLE lam_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at TEXT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );
        $this->adapter->query(
            'CREATE TABLE lam_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                done INTEGER NOT NULL DEFAULT 0,
                start_date TEXT NULL,
                end_date TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    /**
     * ServiceManager の DB アダプタと認証サービスをテスト用に差し替える。
     * dispatch 前に呼ぶこと（コントローラ生成時にこれらが解決されるため）。
     *
     * @param object|null $identity 認証済みユーザーの識別子（null＝ゲスト）
     */
    protected function prepareServices(?object $identity = null): void
    {
        $services = $this->getApplicationServiceLocator();
        $services->setAllowOverride(true);

        // 各 Table ファクトリは get(AdapterInterface::class) で解決するため、
        // そのインターフェース名を直接差し替える（具象 Adapter::class 自体がエイリアスで、
        // それを差し替えても get(AdapterInterface) には反映されない）。
        $services->setService(AdapterInterface::class, $this->adapter);

        $auth = new AuthenticationService(new NonPersistent());
        if ($identity !== null) {
            $auth->getStorage()->write($identity);
        }
        $services->setService(AuthenticationService::class, $auth);

        // CSRF は実セッション（PHP の session_start）に依存させず、配列ストレージを使う。
        // 検証ロジック自体は本物の CsrfGuard をそのまま通す（モックしない）。
        $this->csrf = new CsrfGuard($this->sessionContainer());
        $services->setService(CsrfGuard::class, $this->csrf);

        // セッション ID の再生成も ext/session に依存するため、記録用実装へ差し替える。
        // 実際に ID が変わることは E2E で担保する（RecordingAuthSession の DocBlock 参照）。
        $this->authSession = new RecordingAuthSession($auth);
        $services->setService(AuthSessionInterface::class, $this->authSession);
    }

    /** テスト用のセッションコンテナ（配列ストレージ・プロセス内で完結） */
    private function sessionContainer(): SessionContainer
    {
        $manager = new SessionManager(new StandardConfig(), new ArrayStorage());

        return new SessionContainer('csrf_test', $manager);
    }

    /** フォームに埋まるのと同じ CSRF トークン */
    protected function csrfToken(): string
    {
        return $this->csrf->token();
    }

    /**
     * POST データへ CSRF トークンを足す（通常操作の再現）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withCsrf(array $data = []): array
    {
        return $data + [CsrfGuard::FIELD => $this->csrfToken()];
    }

    /** 認証済みユーザーの識別子（AuthController が書き込む形と同一の stdClass） */
    protected function identity(int $id, string $username = 'alice'): object
    {
        return (object) ['id' => $id, 'username' => $username];
    }

    /** タスクを1件シードし、その id を返す */
    protected function seedTask(int $userId, string $title): int
    {
        $task = new Task();
        $task->title = $title;
        $task->user_id = $userId;
        $this->taskTable->saveTask($task);

        foreach ($this->taskTable->fetchAllByUser($userId) as $row) {
            // fetchAllByUser は id DESC。直近挿入分が先頭に来る
            return (int) $row->id;
        }

        self::fail('シードしたタスクが取得できませんでした');
    }
}
