<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TaskController extends Controller
{
    private const PER_PAGE = 5;

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $tasks = Task::where('user_id', Auth::id())
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%' . $q . '%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('tasks.index', ['tasks' => $tasks, 'q' => $q]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        Auth::user()->tasks()->create($validated);

        return redirect()->route('tasks.index');
    }

    public function duplicate(Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);

        Auth::user()->tasks()->create([
            'title' => $this->copyTitle($task->title),
            'done' => false,
        ]);

        return redirect()->route('tasks.index');
    }

    public function edit(Task $task): View
    {
        $this->authorizeOwnership($task);

        return view('tasks.edit', ['task' => $task]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $task->update($validated);

        return redirect()->route('tasks.index');
    }

    public function toggle(Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);
        $task->update(['done' => ! $task->done]);

        return redirect()->route('tasks.index');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);
        $task->delete();

        return redirect()->route('tasks.index');
    }

    /** 他人のタスクは存在を伏せて 404 にする */
    private function authorizeOwnership(Task $task): void
    {
        abort_if($task->user_id !== Auth::id(), 404);
    }

    /** 「（コピー）」を付与しつつ 255 文字以内に丸める */
    private function copyTitle(string $title): string
    {
        return mb_substr($title . '（コピー）', 0, 255);
    }
}
