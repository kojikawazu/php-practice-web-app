<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\BlockedUrlException;
use App\Services\LinkPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * タスクの CRUD・完了切替・複製・画像配信。すべて本人のタスクに限定する（他人のは 404）。
 * 新規・編集・複製は確認画面を挟む 2 ステップ（入力→セッション退避→確定）で処理する。
 */
class TaskController extends Controller
{
    private const PER_PAGE = 5;

    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'];

    private const CONFIRM_KEY = 'task_confirm';

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $tasks = Task::where('user_id', Auth::id())
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%'.$q.'%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('tasks.index', ['tasks' => $tasks, 'q' => $q]);
    }

    // ---- 新規登録（2ステップ: 確認 → 確定）----

    public function storeConfirm(Request $request, LinkPreviewService $preview): View
    {
        $payload = $this->validateAndStash($request, $preview, 'create', null);

        return view('tasks.confirm', ['mode' => 'create', 'payload' => $payload, 'task' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = session(self::CONFIRM_KEY);
        if (! $payload || ($payload['mode'] ?? null) !== 'create') {
            return redirect()->route('tasks.index');
        }

        $data = $this->dataFromPayload($payload);
        if (! empty($payload['image_tmp'])) {
            $data['image_path'] = $this->moveTemp($payload['image_tmp']);
        }

        Auth::user()->tasks()->create($data);
        session()->forget(self::CONFIRM_KEY);

        return redirect()->route('tasks.index');
    }

    // ---- 編集（2ステップ）----

    public function edit(Task $task): View
    {
        $this->authorizeOwnership($task);

        return view('tasks.edit', ['task' => $task]);
    }

    public function updateConfirm(Request $request, Task $task, LinkPreviewService $preview): View
    {
        $this->authorizeOwnership($task);
        $payload = $this->validateAndStash($request, $preview, 'edit', $task);

        return view('tasks.confirm', ['mode' => 'edit', 'payload' => $payload, 'task' => $task]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeOwnership($task);

        $payload = session(self::CONFIRM_KEY);
        if (! $payload || ($payload['mode'] ?? null) !== 'edit' || ($payload['task_id'] ?? null) !== $task->id) {
            return redirect()->route('tasks.index');
        }

        $data = $this->dataFromPayload($payload);
        if (! empty($payload['image_tmp'])) {
            $this->deleteImage($task->image_path);
            $data['image_path'] = $this->moveTemp($payload['image_tmp']);
        }

        $task->update($data);
        session()->forget(self::CONFIRM_KEY);

        return redirect()->route('tasks.index');
    }

    // ---- 複製（確認 → 実行）----

    public function duplicateConfirm(Task $task): View
    {
        $this->authorizeOwnership($task);

        return view('tasks.confirm', ['mode' => 'duplicate', 'payload' => null, 'task' => $task]);
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
            'url' => $task->url,
            'preview_title' => $task->preview_title,
            'preview_image' => $task->preview_image,
        ]);

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

    /**
     * 入力を検証し、確認ステップ用にセッション＋一時ファイルへ退避する。
     * 画像は uploads ディスクの tmp/ に一時保存、URL はこの時点で安全に取得する。
     *
     * @return array<string, mixed> 確認画面・確定処理で使う退避ペイロード
     */
    private function validateAndStash(Request $request, LinkPreviewService $preview, string $mode, ?Task $task): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image' => self::IMAGE_RULES,
            'url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        // 前回の未確定 tmp が残っていれば掃除
        $this->clearPendingTemp();

        $payload = [
            'mode' => $mode,
            'task_id' => $task?->id,
            'title' => $validated['title'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'image_tmp' => null,
            'image_name' => null,
            'url' => null,
            'preview_title' => null,
            'preview_image' => null,
        ];

        if ($request->hasFile('image')) {
            $payload['image_tmp'] = $request->file('image')->store('tmp', 'uploads');
            $payload['image_name'] = $request->file('image')->getClientOriginalName();
        }

        $url = trim((string) ($validated['url'] ?? ''));
        if ($url !== '') {
            try {
                $meta = $preview->fetch($url);
            } catch (BlockedUrlException $e) {
                $this->deleteImage($payload['image_tmp']); // 取得失敗時は一時画像も破棄
                throw ValidationException::withMessages(['url' => $e->getMessage()]);
            }
            $payload['url'] = $url;
            $payload['preview_title'] = $meta['title'] ?? null;
            $payload['preview_image'] = $meta['image'] ?? null;
        }

        session([self::CONFIRM_KEY => $payload]);

        return $payload;
    }

    /**
     * 退避ペイロードから Task の保存用データ（画像を除く）を組み立てる。
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function dataFromPayload(array $p): array
    {
        return [
            'title' => $p['title'],
            'start_date' => $p['start_date'] ?? null,
            'end_date' => $p['end_date'] ?? null,
            'url' => $p['url'] ?? null,
            'preview_title' => $p['preview_title'] ?? null,
            'preview_image' => $p['preview_image'] ?? null,
        ];
    }

    /** 未確定で残っているセッションの一時画像を削除する */
    private function clearPendingTemp(): void
    {
        $prev = session(self::CONFIRM_KEY);
        if ($prev && ! empty($prev['image_tmp'])) {
            $this->deleteImage($prev['image_tmp']);
        }
    }

    /** tmp/ の一時画像を本保存（ランダム名）へ移動し、保存パスを返す */
    private function moveTemp(string $tmp): string
    {
        $ext = pathinfo($tmp, PATHINFO_EXTENSION);
        $final = (string) Str::uuid().($ext ? '.'.$ext : '');
        Storage::disk('uploads')->move($tmp, $final);

        return $final;
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
        $copy = (string) Str::uuid().($ext ? '.'.$ext : '');
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
        return mb_substr($title.'（コピー）', 0, 255);
    }
}
