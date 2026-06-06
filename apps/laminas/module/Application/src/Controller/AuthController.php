<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\UserTable;
use Application\Service\PasswordHasher;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class AuthController extends AbstractActionController
{
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

        $error = null;
        $request = $this->getRequest();

        if ($request->isPost()) {
            $username = trim((string) $this->params()->fromPost('username', ''));
            $password = (string) $this->params()->fromPost('password', '');

            $user = $this->users->findByUsername($username);
            if ($user && $this->hasher->verify($password, (string) $user->password)) {
                $this->auth->getStorage()->write(
                    (object) ['id' => (int) $user->id, 'username' => $user->username]
                );

                return $this->redirect()->toRoute('tasks');
            }

            $error = 'ユーザー名またはパスワードが正しくありません。';
        }

        return new ViewModel(['error' => $error]);
    }

    public function registerAction()
    {
        if ($this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('tasks');
        }

        $error = null;
        $request = $this->getRequest();

        if ($request->isPost()) {
            $username = trim((string) $this->params()->fromPost('username', ''));
            $password = (string) $this->params()->fromPost('password', '');

            if ($username === '' || mb_strlen($password) < 8) {
                $error = 'ユーザー名は必須、パスワードは8文字以上にしてください。';
            } elseif ($this->users->findByUsername($username)) {
                $error = 'そのユーザー名は既に使われています。';
            } else {
                $this->users->create($username, $this->hasher->hash($password));
                $user = $this->users->findByUsername($username);
                $this->auth->getStorage()->write(
                    (object) ['id' => (int) $user->id, 'username' => $user->username]
                );

                return $this->redirect()->toRoute('tasks');
            }
        }

        return new ViewModel(['error' => $error]);
    }

    public function logoutAction()
    {
        $this->auth->clearIdentity();

        return $this->redirect()->toRoute('login');
    }
}
