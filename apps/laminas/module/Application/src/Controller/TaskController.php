<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\InputFilter\ErrorFormatter;
use Application\InputFilter\TaskInputFilter;
use Application\Model\Task;
use Application\Model\TaskTable;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\Response as HttpResponse;
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
     * 完了・未完了を切り替える。
     *
     * 状態を変えるため POST 限定（GET は 405）。CSRF 検証は Module の一括リスナーが担当する。
     * 所有者スコープは getForUser（取得）と saveTask の user_id 条件（更新）で二重に効く。
     *
     * @psalm-suppress PossiblyUnusedMethod ルーティング（module.config.php の tasks ルート）が
     *                 action 名から解決して呼ぶため、静的解析からは呼び出し元が見えない
     *                 （dead-code.md の「フレームワーク規約」例外）。
     */
    public function toggleAction(): HttpResponse
    {
        if ($response = $this->rejectUnlessPost()) {
            return $response;
        }

        if (! $this->auth->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $this->currentUserId();
        $task = $this->table->getForUser($this->routeId(), $userId);

        if ($task) {
            $task->done = ! $task->done;
            $this->table->saveTask($task);
        }

        return $this->redirect()->toRoute('tasks');
    }

    /**
     * ルートの id を取り出す。
     * params() プラグインは戻り値が mixed のため、型の付く RouteMatch から取る。
     */
    private function routeId(): int
    {
        $routeMatch = $this->getEvent()->getRouteMatch();
        if ($routeMatch === null) {
            return 0;
        }

        /** @var mixed $id */
        $id = $routeMatch->getParam('id', 0);

        return is_numeric($id) ? (int) $id : 0;
    }

    /** ログイン中のユーザー id（未認証は 0）。identity は AuthController が書き込む stdClass */
    private function currentUserId(): int
    {
        /** @var object{id: int|string, username: string}|null $identity */
        $identity = $this->auth->getIdentity();

        return $identity === null ? 0 : (int) $identity->id;
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
