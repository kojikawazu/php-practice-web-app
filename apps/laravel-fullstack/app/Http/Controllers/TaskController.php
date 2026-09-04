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
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * タスクの CRUD・完了切替・複製・画像配信。すべて本人のタスクに限定する（他人のは 404）。
 * 新規・編集・複製は確認画面を挟む 2 ステップ（入力→セッション退避→確定）で処理する。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 4）:
 * - laravel-api: 同名 TaskController。スコープの考え方は同じだが、返すのは JSON で redirect がない。
 * - laminas: module/Application/src/Controller/TaskController.php。認証判定を各アクション冒頭に書き、
 *   所有者条件は TaskTable::getForUser() 側へ寄せる（本クラスのように Auth ファサードを直接使わない）。
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
        $created = null;
        if (! empty($payload['image_tmp'])) {
            $created = $this->moveTemp($payload['image_tmp']);
            $data['image_path'] = $created;
        }

        $this->persistOrDiscard(fn () => Auth::user()->tasks()->create($data), $created);
        session()->forget(self::CONFIRM_KEY);

        return redirect()->route('tasks.index');
    }

    /**
     * 確認画面のキャンセル。保留中の操作（セッション）と一時画像を破棄する。
     *
     * GET のリンクで一覧へ戻るだけだと、セッションの保留ペイロードが残るため
     * **キャンセルしたはずの確定 POST を後から実行できてしまう**（ブラウザバックや
     * 別タブからの再送）。破棄を伴う操作なので POST + CSRF にしている。
     *
     * 保留が無い状態で呼ばれても何もせずリダイレクトする（二重送信・再読み込みで
     * エラーにしない）。
     */
    public function cancelConfirm(): RedirectResponse
    {
        $payload = session(self::CONFIRM_KEY);
        $payload = is_array($payload) ? $payload : [];

        $this->clearPendingTemp();
        session()->forget(self::CONFIRM_KEY);

        // 戻り先はセッションの内容だけで決める。リクエストから受け取った URL へ
        // リダイレクトすると、任意の URL へ誘導できる口を作ってしまうため。
        if (($payload['mode'] ?? null) === 'edit' && ! empty($payload['task_id'])) {
            return redirect()->route('tasks.edit', $payload['task_id']);
        }

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

        // 旧画像は「DB 反映が成功してから」削除する。
        // 先に消すと、移動や DB 更新が失敗した時点で旧画像を復旧できなくなる。
        $data = $this->dataFromPayload($payload);
        $replaced = $task->image_path;
        $created = null;
        if (! empty($payload['image_tmp'])) {
            $created = $this->moveTemp($payload['image_tmp']);
            $data['image_path'] = $created;
        }

        // update() はモデルイベントで中断されると例外を投げずに false を返す。
        // そのまま進むと DB 未更新のまま旧画像を消してしまうため、失敗として扱う。
        $this->persistOrDiscard(function () use ($task, $data): void {
            if (! $task->update($data)) {
                throw new RuntimeException('タスクの更新に失敗しました。');
            }
        }, $created);

        if ($created !== null) {
            $this->deleteImage($replaced);
        }

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

        // 画像を先にコピーしてから DB に反映する（DB 失敗時はコピーを補償削除）
        $created = $this->copyImage($task->image_path);

        $this->persistOrDiscard(fn () => Auth::user()->tasks()->create([
            'title' => $this->copyTitle($task->title),
            'done' => false,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'end_date' => $task->end_date?->format('Y-m-d'),
            'image_path' => $created,
            'url' => $task->url,
            'preview_title' => $task->preview_title,
            'preview_image' => $task->preview_image,
        ]), $created);

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

        // DB を先に消す。逆順にすると DB 削除が失敗したときに画像だけ失われ、
        // 再試行しても復旧できない（孤児ファイルは残っても後から掃除できる）。
        $imagePath = $task->image_path;
        if (! $task->delete()) {
            throw new RuntimeException('タスクの削除に失敗しました。');
        }
        $this->deleteImage($imagePath);

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
            $tmp = $request->file('image')->store('tmp', 'uploads');
            if (! is_string($tmp) || $tmp === '') {
                throw new RuntimeException('画像の一時保存に失敗しました。');
            }
            $payload['image_tmp'] = $tmp;
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
        // 移動に失敗したら DB へ進まない。tmp/ は残るが、次の確認操作で clearPendingTemp が掃除する。
        if (! Storage::disk('uploads')->move($tmp, $final)) {
            throw new RuntimeException('画像の保存に失敗しました。');
        }

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
        if (! Storage::disk('uploads')->copy($path, $copy)) {
            throw new RuntimeException('画像の複製に失敗しました。');
        }

        return $copy;
    }

    /**
     * DB 反映を実行し、失敗したらこの操作で作成したファイルを削除して例外を再送出する。
     *
     * ファイルシステムは DB トランザクションに参加できない（削除したファイルはロールバックで戻らない）。
     * そのため「ファイルを先に作る → DB → 失敗なら補償削除」の順で整合を取る。
     * 許容するのは孤児ファイルのみで、DB に実在しない path を残さないことを不変条件とする。
     * 作成が中断された場合（ファイルは残り DB 行が無い）は孤児ファイルであり、不変条件は破れない。
     *
     * @template TResult
     *
     * @param  callable(): TResult  $persist  DB 反映処理
     * @param  string|null  $created  この操作で新規作成したファイル（なければ null）
     * @return TResult
     */
    private function persistOrDiscard(callable $persist, ?string $created): mixed
    {
        try {
            return $persist();
        } catch (Throwable $e) {
            $this->deleteImage($created);

            throw $e;
        }
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
