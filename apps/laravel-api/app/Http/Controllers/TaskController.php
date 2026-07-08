<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * タスクの JSON CRUD・複製・画像配信（すべて auth:sanctum 保護）。
 * 本人のタスクに限定し、他人のリソースは存在を伏せて 404 にする。
 */
class TaskController extends Controller
{
    private const DEFAULT_PER_PAGE = 5;
    private const MAX_PER_PAGE = 50;
    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'];

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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image' => self::IMAGE_RULES,
        ]);
        unset($validated['image']);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $this->storeImage($request->file('image'));
        }

        $task = $request->user()->tasks()->create($validated);

        return response()->json($task, 201);
    }

    public function duplicate(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $copy = $request->user()->tasks()->create([
            'title' => mb_substr($task->title . '（コピー）', 0, 255),
            'done' => false,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'end_date' => $task->end_date?->format('Y-m-d'),
            'image_path' => $this->copyImage($task->image_path),
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image' => self::IMAGE_RULES,
        ]);
        unset($validated['image']);

        if ($request->hasFile('image')) {
            $this->deleteImage($task->image_path);
            $validated['image_path'] = $this->storeImage($request->file('image'));
        }

        $task->update($validated);

        return response()->json($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);
        $this->deleteImage($task->image_path);
        $task->delete();

        return response()->json(null, 204);
    }

    /** 所有者本人にのみ画像ファイルを返す */
    public function image(Request $request, Task $task): BinaryFileResponse
    {
        $this->authorizeOwnership($request, $task);
        abort_if(! $task->image_path || ! Storage::disk('uploads')->exists($task->image_path), 404);

        return response()->file(Storage::disk('uploads')->path($task->image_path));
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store('', 'uploads');
    }

    private function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('uploads')->exists($path)) {
            Storage::disk('uploads')->delete($path);
        }
    }

    private function copyImage(?string $path): ?string
    {
        if (! $path || ! Storage::disk('uploads')->exists($path)) {
            return null;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $copy = (string) Str::uuid() . ($ext ? '.' . $ext : '');
        Storage::disk('uploads')->copy($path, $copy);

        return $copy;
    }

    /** 他人のタスクは存在を伏せて 404 にする */
    private function authorizeOwnership(Request $request, Task $task): void
    {
        abort_if($task->user_id !== $request->user()->id, 404);
    }
}
