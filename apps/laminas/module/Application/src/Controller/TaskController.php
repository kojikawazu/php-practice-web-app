<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\Task;
use Application\Model\TaskTable;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class TaskController extends AbstractActionController
{
    public function __construct(
        private TaskTable $table,
        private AuthenticationService $auth
    ) {
    }

    public function indexAction()
    {
        if (! $this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        $user = $this->auth->getIdentity();
        $request = $this->getRequest();

        if ($request->isPost()) {
            $title = trim((string) $this->params()->fromPost('title', ''));
            if ($title !== '') {
                $task = new Task();
                $task->title = $title;
                $task->user_id = (int) $user->id;
                $this->table->saveTask($task);
            }

            return $this->redirect()->toRoute('tasks');
        }

        return new ViewModel([
            'tasks'    => $this->table->fetchAllByUser((int) $user->id),
            'username' => $user->username,
        ]);
    }

    public function editAction()
    {
        if (! $this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        $user = $this->auth->getIdentity();
        $id = (int) $this->params()->fromRoute('id', 0);
        $task = $this->table->getForUser($id, (int) $user->id);

        // 他人のタスク・存在しない場合は一覧へ戻す
        if (! $task) {
            return $this->redirect()->toRoute('tasks');
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $title = trim((string) $this->params()->fromPost('title', ''));
            if ($title === '') {
                return new ViewModel(['task' => $task, 'error' => 'タイトルは必須です。']);
            }

            $task->title = $title;
            $this->table->saveTask($task);

            return $this->redirect()->toRoute('tasks');
        }

        return new ViewModel(['task' => $task, 'error' => null]);
    }

    public function deleteAction()
    {
        if (! $this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        $user = $this->auth->getIdentity();
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->table->deleteForUser($id, (int) $user->id);
        }

        return $this->redirect()->toRoute('tasks');
    }
}
