<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\BlockedUrlException;
use App\Services\LinkPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaskController extends Controller
{
    private const PER_PAGE = 5;
    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'];

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

    public function store(Request $request, LinkPreviewService $preview): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image' => self::IMAGE_RULES,
            'url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $data = [
            'title' => $validated['title'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        }
        $this->applyPreview($data, $validated['url'] ?? null, $preview);

        Auth::user()->tasks()->create($data);

        return redirect()->route('tasks.index');
    }

    public function duplicate(Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);

        Auth::user()->tasks()->create([
            'title' => $this->copyTitle($task->title),
            'done' => false,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'end_date' => $task->end_date?->format('Y-m-d'),
            'image_path' => $this->copyImage($task->image_path),
        ]);

        return redirect()->route('tasks.index');
    }

    public function edit(Task $task): View
    {
        $this->authorizeOwnership($task);

        return view('tasks.edit', ['task' => $task]);
    }

    public function update(Request $request, Task $task, LinkPreviewService $preview): RedirectResponse
    {
        $this->authorizeOwnership($task);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image' => self::IMAGE_RULES,
            'url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $data = [
            'title' => $validated['title'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];
        if ($request->hasFile('image')) {
            $this->deleteImage($task->image_path);
            $data['image_path'] = $this->storeImage($request->file('image'));
        }
        $this->applyPreview($data, $validated['url'] ?? null, $preview);

        $task->update($data);

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
        $this->deleteImage($task->image_path);
        $task->delete();

        return redirect()->route('tasks.index');
    }

    /** 所有者本人にのみ画像ファイルを返す（公開ディレクトリ外＝URL を知っても他人は見られない）*/
    public function image(Task $task): BinaryFileResponse
    {
        $this->authorizeOwnership($task);
        abort_if(! $task->image_path || ! Storage::disk('uploads')->exists($task->image_path), 404);

        return response()->file(Storage::disk('uploads')->path($task->image_path));
    }

    /** URL を安全に取得してプレビュー（title/og:image）を $data に反映。空 URL はクリア。 */
    private function applyPreview(array &$data, ?string $url, LinkPreviewService $preview): void
    {
        $url = $url !== null ? trim($url) : '';
        if ($url === '') {
            $data['url'] = null;
            $data['preview_title'] = null;
            $data['preview_image'] = null;

            return;
        }

        try {
            $meta = $preview->fetch($url);
        } catch (BlockedUrlException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $data['url'] = $url;
        $data['preview_title'] = $meta['title'] ?? null;
        $data['preview_image'] = $meta['image'] ?? null;
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

    /** 複製用に画像を別ファイルへコピー（共有して片方の削除で消えるのを防ぐ）*/
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
