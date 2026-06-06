<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(): View
    {
        $tasks = Task::where('user_id', Auth::id())->orderByDesc('id')->get();

        return view('tasks.index', ['tasks' => $tasks]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        Auth::user()->tasks()->create($validated);

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
}
