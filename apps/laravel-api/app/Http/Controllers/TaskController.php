<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * タスクの JSON CRUD・複製・画像配信（すべて auth:sanctum 保護）。
 * 本人のタスクに限定し、他人のリソースは存在を伏せて 404 にする。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 4）:
 * - laravel-fullstack: 同名 TaskController。Eloquent の使い方は同じで、出力が Blade + redirect になり、
 *   さらに 2 ステップ確認画面（storeConfirm 等）が加わる。
 * - laminas: module/Application/src/Controller/TaskController.php。TableGateway で明示 SQL を組む。
 * 未認証時の扱いも三者三様（本アプリ=401 / fullstack=auth ミドルウェアでリダイレクト / laminas=各アクションで判定）。
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
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%'.$q.'%'))
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

        $created = null;
        if ($request->hasFile('image')) {
            $created = $this->storeImage($request->file('image'));
            $validated['image_path'] = $created;
        }

        $task = $this->persistOrDiscard(fn () => $request->user()->tasks()->create($validated), $created);

        return response()->json($task, 201);
    }

    public function duplicate(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        // 画像を先にコピーしてから DB に反映する（DB 失敗時はコピーを補償削除）
        $created = $this->copyImage($task->image_path);

        $copy = $this->persistOrDiscard(fn () => $request->user()->tasks()->create([
            'title' => mb_substr($task->title.'（コピー）', 0, 255),
            'done' => false,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'end_date' => $task->end_date?->format('Y-m-d'),
            'image_path' => $created,
        ]), $created);

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

        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'image' => self::IMAGE_RULES,
        ];

        // 更新後に確定する開始日・終了日を組み立ててから整合性を検証する。
        // store() のように 'after_or_equal:start_date' と書くと、比較対象を
        // **リクエストの中から**探すため、片側だけの PATCH では対象が消えて素通りする。
        $start = $this->resolveDate($request, 'start_date', $task->start_date?->format('Y-m-d'));
        $end = $this->resolveDate($request, 'end_date', $task->end_date?->format('Y-m-d'));

        if ($request->hasAny(['start_date', 'end_date']) && $start !== null && $end !== null) {
            // エラーはクライアントが送ったフィールドに載せる。送っていない側に出しても直しようがない
            if ($request->has('end_date')) {
                $rules['end_date'][] = 'after_or_equal:'.$start;
            } else {
                $rules['start_date'][] = 'before_or_equal:'.$end;
            }
        }

        $validated = $request->validate($rules);
        unset($validated['image']);

        // 旧画像は「DB 反映が成功してから」削除する。
        // 先に消すと、保存や DB 更新が失敗した時点で旧画像を復旧できなくなる。
        $replaced = $task->image_path;
        $created = null;
        if ($request->hasFile('image')) {
            $created = $this->storeImage($request->file('image'));
            $validated['image_path'] = $created;
        }

        // update() はモデルイベントで中断されると例外を投げずに false を返す。
        // そのまま進むと DB 未更新のまま旧画像を消してしまうため、失敗として扱う。
        $this->persistOrDiscard(function () use ($task, $validated): void {
            if (! $task->update($validated)) {
                throw new RuntimeException('タスクの更新に失敗しました。');
            }
        }, $created);

        if ($created !== null) {
            $this->deleteImage($replaced);
        }

        return response()->json($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        // DB を先に消す。逆順にすると DB 削除が失敗したときに画像だけ失われ、
        // 再試行しても復旧できない（孤児ファイルは残っても後から掃除できる）。
        $imagePath = $task->image_path;
        if (! $task->delete()) {
            throw new RuntimeException('タスクの削除に失敗しました。');
        }
        $this->deleteImage($imagePath);

        return response()->json(null, 204);
    }

    /** 所有者本人にのみ画像ファイルを返す */
    public function image(Request $request, Task $task): BinaryFileResponse
    {
        $this->authorizeOwnership($request, $task);
        abort_if(! $task->image_path || ! Storage::disk('uploads')->exists($task->image_path), 404);

        return response()->file(Storage::disk('uploads')->path($task->image_path));
    }

    /**
     * 画像を保存し、保存パスを返す。失敗は戻り値ではなく例外で伝える。
     * 呼び出し側は「保存できた」前提で DB へ path を書くため、false を通してはならない。
     */
    private function storeImage(UploadedFile $file): string
    {
        $path = $file->store('', 'uploads');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('画像の保存に失敗しました。');
        }

        return $path;
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

    /**
     * 更新後に確定する日付（Y-m-d）を返す。リクエストに含まれていればその値、
     * 含まれていなければ保存済みの値を使う。
     *
     * 日付として解釈できない入力は null を返して比較ルールへ持ち込まない。
     * その値自体は 'date' ルールが 422 にするため、同じ誤りを二重に報告しない。
     *
     * @param  string|null  $stored  保存済みの値（Y-m-d）
     */
    private function resolveDate(Request $request, string $key, ?string $stored): ?string
    {
        if (! $request->has($key)) {
            return $stored;
        }

        $value = $request->input($key);

        // 'date' ルールと同じ strtotime で解釈できるかを判定する
        return is_string($value) && $value !== '' && strtotime($value) !== false ? $value : null;
    }

    /** 他人のタスクは存在を伏せて 404 にする */
    private function authorizeOwnership(Request $request, Task $task): void
    {
        abort_if($task->user_id !== $request->user()->id, 404);
    }
}
