<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\Task;
use Application\Model\TaskTable;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class TaskController extends AbstractActionController
{
    public function __construct(private TaskTable $table)
    {
    }

    public function indexAction()
    {
        $request = $this->getRequest();

        if ($request->isPost()) {
            $title = trim((string) $this->params()->fromPost('title', ''));
            if ($title !== '') {
                $task = new Task();
                $task->title = $title;
                $this->table->saveTask($task);
            }

            return $this->redirect()->toRoute('tasks');
        }

        return new ViewModel(['tasks' => $this->table->fetchAll()]);
    }

    public function deleteAction()
    {
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->table->deleteTask($id);
        }

        return $this->redirect()->toRoute('tasks');
    }
}
