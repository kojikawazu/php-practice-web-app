<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\InputFilter\ErrorFormatter;
use Application\InputFilter\TaskInputFilter;
use Application\Model\Task;
use Application\Model\TaskTable;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * タスクの一覧・作成・編集・複製・削除。すべてログインユーザー本人のタスクに限定する
 * （所有者スコープは TaskTable のメソッド側で担保）。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 4）:
 * - laravel-fullstack / laravel-api: app/Http/Controllers/TaskController.php。ミドルウェアが認証境界を
 *   受け持つため、本クラスのような hasIdentity() 判定はアクション内に現れない。
 * - 依存（TaskTable / AuthenticationService）は TaskControllerFactory が注入する。Laravel 側の
 *   コンテナ自動解決に対し、Laminas は「誰が何を注入するか」をファクトリに明示するのが対照的。
 */
class TaskController extends AbstractActionController
{
    use RequiresPostTrait;

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

        $errors = [];
        if ($request->isPost()) {
            $filter = new TaskInputFilter();
            $filter->setData($request->getPost()->toArray());
            if ($filter->isValid()) {
                $values = $filter->getValues();
                $task = new Task();
                $task->title = $values['title'];
                $task->user_id = (int) $user->id;
                $this->applyDates($task, $values);
                $this->table->saveTask($task);

                return $this->redirect()->toRoute('tasks');
            }
            $errors = ErrorFormatter::flatten($filter);
        }

        $perPage = 5;
        $q = trim((string) $this->params()->fromQuery('q', ''));
        $page = max(1, (int) $this->params()->fromQuery('page', 1));

        $total = $this->table->countByUser((int) $user->id, $q);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        return new ViewModel([
            'tasks'      => $this->table->fetchPageByUser((int) $user->id, $perPage, $offset, $q),
            'username'   => $user->username,
            'q'          => $q,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'errors'     => $errors,
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
        $errors = [];
        if ($request->isPost()) {
            $filter = new TaskInputFilter();
            $filter->setData($request->getPost()->toArray());
            if ($filter->isValid()) {
                $values = $filter->getValues();
                $task->title = $values['title'];
                $this->applyDates($task, $values);
                $this->table->saveTask($task);

                return $this->redirect()->toRoute('tasks');
            }
            $errors = ErrorFormatter::flatten($filter);
        }

        return new ViewModel(['task' => $task, 'errors' => $errors]);
    }

    public function duplicateAction()
    {
        if ($response = $this->rejectUnlessPost()) {
            return $response;
        }

        if (! $this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        $user = $this->auth->getIdentity();
        $id = (int) $this->params()->fromRoute('id', 0);
        $task = $this->table->getForUser($id, (int) $user->id);

        if ($task) {
            $copy = new Task();
            $copy->title = mb_substr($task->title . '（コピー）', 0, 255);
            $copy->done = false;
            $copy->user_id = (int) $user->id;
            $copy->start_date = $task->start_date;
            $copy->end_date = $task->end_date;
            $this->table->saveTask($copy);
        }

        return $this->redirect()->toRoute('tasks');
    }

    /**
     * InputFilter の値から日付（空文字は null）を Task に反映する。
     *
     * @param array<string, mixed> $values
     */
    private function applyDates(Task $task, array $values): void
    {
        $task->start_date = ($values['start_date'] ?? '') !== '' ? $values['start_date'] : null;
        $task->end_date = ($values['end_date'] ?? '') !== '' ? $values['end_date'] : null;
    }

    public function deleteAction()
    {
        if ($response = $this->rejectUnlessPost()) {
            return $response;
        }

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
