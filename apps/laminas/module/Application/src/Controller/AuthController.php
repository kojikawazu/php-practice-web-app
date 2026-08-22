<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\InputFilter\ErrorFormatter;
use Application\InputFilter\LoginInputFilter;
use Application\InputFilter\RegisterInputFilter;
use Application\Model\UserTable;
use Application\Service\PasswordHasher;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * ログイン・新規登録・ログアウト。認証成功時は identity をセッション（AuthenticationService の
 * Storage）に保存し、パスワード照合は PasswordHasher（bcrypt）へ委譲する。
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
        private PasswordHasher $hasher
    ) {
    }

    public function loginAction()
    {
        if ($this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('tasks');
        }

        $errors = [];
        $request = $this->getRequest();

        if ($request->isPost()) {
            $filter = new LoginInputFilter();
            $filter->setData($request->getPost()->toArray());

            if (! $filter->isValid()) {
                $errors = ErrorFormatter::flatten($filter);
            } else {
                $values = $filter->getValues();
                $user = $this->users->findByUsername($values['username']);
                if ($user && $this->hasher->verify($values['password'], (string) $user->password)) {
                    $this->auth->getStorage()->write(
                        (object) ['id' => (int) $user->id, 'username' => $user->username]
                    );

                    return $this->redirect()->toRoute('tasks');
                }

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

        return $this->redirect()->toRoute('login');
    }
}
