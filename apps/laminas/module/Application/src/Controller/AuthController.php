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
        $this->auth->clearIdentity();

        return $this->redirect()->toRoute('login');
    }
}
