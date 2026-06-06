<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    private const DEFAULT_PER_PAGE = 5;
    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $tasks = $request->user()->tasks()
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%' . $q . '%'))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($tasks);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $task = $request->user()->tasks()->create($validated);

        return response()->json($task, 201);
    }

    public function duplicate(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $copy = $request->user()->tasks()->create([
            'title' => mb_substr($task->title . '（コピー）', 0, 255),
            'done' => false,
        ]);

        return response()->json($copy, 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        return response()->json($task);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $task->update($validated);

        return response()->json($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);
        $task->delete();

        return response()->json(null, 204);
    }

    /** 他人のタスクは存在を伏せて 404 にする */
    private function authorizeOwnership(Request $request, Task $task): void
    {
        abort_if($task->user_id !== $request->user()->id, 404);
    }
}
