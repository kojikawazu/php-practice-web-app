<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\InputFilter\ErrorFormatter;
use Application\InputFilter\LoginInputFilter;
use Application\InputFilter\RegisterInputFilter;
use Application\Model\UserTable;
use Application\Service\AuthSessionInterface;
use Application\Service\AuthThrottle;
use Application\Service\PasswordHasher;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\PhpEnvironment\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * ログイン・新規登録・ログアウト。認証成功時は identity をセッション（AuthenticationService の
 * Storage）に保存し、パスワード照合は PasswordHasher（bcrypt）へ委譲する。
 *
 * 認証状態が変わる瞬間のセッション操作（ID 再生成・破棄）は AuthSessionInterface に委ねる。
 * CSRF のように Module のリスナーで一括処理しないのは、「認証に成功した瞬間」がここでしか
 * 分からないため（横断的に判定できる関心事ではない）。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 2）:
 * - laravel-fullstack: app/Http/Controllers/AuthController.php。同じセッション方式だが、照合・セッション
 *   保存・再生成を Auth::attempt() + session()->regenerate() が一括で行う。本クラスはその内訳が展開された形。
 * - laravel-api: 同上のパスにトークン方式。セッションを持たない。
 */
class AuthController extends AbstractActionController
{
    use RequiresPostTrait;

    public function __construct(
        private AuthenticationService $auth,
        private UserTable $users,
        private PasswordHasher $hasher,
        private AuthSessionInterface $session,
        private AuthThrottle $throttle
    ) {
    }

    /** 上限超過時の表示。何回で解けるかは伝えない（総当たり側に情報を与えないため） */
    private const THROTTLED_MESSAGE = '試行回数が多すぎます。しばらく待ってから再度お試しください。';

    /**
     * 上限超過として画面を返す。
     *
     * ステータスを 429 にしているのは、両 Laravel の `throttle` ミドルウェアと**同じ意味を
     * 同じコードで返す**ため（`docs/07` / `docs/06`）。laminas は画面を返すが、
     * 「200 で普通のログイン画面」に見せると E2E からも監視からも区別できない。
     */
    private function throttled(): ViewModel
    {
        $response = $this->getResponse();
        if ($response instanceof HttpResponse) {
            $response->setStatusCode(429);
        }

        return new ViewModel(['errors' => [self::THROTTLED_MESSAGE]]);
    }

    /** 試行を数える単位になるクライアント IP。取れない場合も 1 つのキーへまとめる */
    private function clientIp(): string
    {
        $request = $this->getRequest();

        if (! $request instanceof HttpRequest) {
            return 'unknown';
        }

        /** @var string|null $ip REMOTE_ADDR は文字列か未設定（CLI からの dispatch では入らない） */
        $ip = $request->getServer('REMOTE_ADDR');

        return $ip !== null && $ip !== '' ? $ip : 'unknown';
    }

    public function loginAction()
    {
        if ($this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('tasks');
        }

        $errors = [];
        $request = $this->getRequest();

        if ($request->isPost()) {
            $ip = $this->clientIp();

            // IP 単位の頭打ちは入力の妥当性より先に見る。後にすると、壊れた入力を
            // 投げ続けるだけで回数を消費せずに済んでしまう。
            if ($this->throttle->isIpBlocked($ip)) {
                return $this->throttled();
            }

            $filter = new LoginInputFilter();
            $filter->setData($request->getPost()->toArray());

            if (! $filter->isValid()) {
                $this->throttle->recordIpAttempt($ip);
                $errors = ErrorFormatter::flatten($filter);
            } else {
                $values = $filter->getValues();
                $username = (string) $values['username'];

                if ($this->throttle->isUserBlocked($username, $ip)) {
                    return $this->throttled();
                }

                $user = $this->users->findByUsername($username);
                if ($user && $this->hasher->verify($values['password'], (string) $user->password)) {
                    // 成功したらそのアカウントの履歴を捨てる（正規利用者を巻き込まない）
                    $this->throttle->clearLoginAttempts($username, $ip);
                    // identity を書く前に ID を振り直す（セッション固定攻撃対策・docs/06）。
                    // この順序なら「認証済み状態は必ず新しい ID の下でだけ存在する」と言い切れる。
                    $this->session->regenerate();
                    $this->auth->getStorage()->write(
                        (object) ['id' => (int) $user->id, 'username' => $user->username]
                    );

                    return $this->redirect()->toRoute('tasks');
                }

                $this->throttle->recordLoginFailure($username, $ip);
                $errors = ['ユーザー名またはパスワードが正しくありません。'];
            }
        }

        return new ViewModel(['errors' => $errors]);
    }

    public function registerAction()
    {
        if ($this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('tasks');
        }

        $errors = [];
        $request = $this->getRequest();

        if ($request->isPost()) {
            $ip = $this->clientIp();

            if ($this->throttle->isIpBlocked($ip)) {
                return $this->throttled();
            }

            // 登録はアカウントを増やす操作なので、成否によらず IP 単位で 1 件数える
            $this->throttle->recordIpAttempt($ip);

            $filter = new RegisterInputFilter();
            $filter->setData($request->getPost()->toArray());

            if (! $filter->isValid()) {
                $errors = ErrorFormatter::flatten($filter);
            } else {
                $values = $filter->getValues();
                if ($this->users->findByUsername($values['username'])) {
                    $errors = ['そのユーザー名は既に使われています。'];
                } else {
                    $this->users->create($values['username'], $this->hasher->hash($values['password']));
                    $user = $this->users->findByUsername($values['username']);
                    // 登録直後の自動ログインも「認証成功」なので、ログインと同じ順序で再生成する
                    $this->session->regenerate();
                    $this->auth->getStorage()->write(
                        (object) ['id' => (int) $user->id, 'username' => $user->username]
                    );

                    return $this->redirect()->toRoute('tasks');
                }
            }
        }

        return new ViewModel(['errors' => $errors]);
    }

    public function logoutAction()
    {
        if ($response = $this->rejectUnlessPost()) {
            return $response;
        }

        $this->auth->clearIdentity();
        // identity を消すだけだと、同じ ID のセッション（CSRF トークンを含む）が残り続ける。
        // 中身ごと破棄して ID も作り直す。
        $this->session->invalidate();

        return $this->redirect()->toRoute('login');
    }
}
