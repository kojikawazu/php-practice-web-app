<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 画像ファイルと DB の整合性（失敗注入）。
 *
 * ファイルシステムは DB トランザクションに参加できないため、順序と補償で整合を取る。
 * 守る不変条件は「DB の image_path は必ず実在する」の一点で、孤児ファイル
 * （ファイルはあるが DB から参照されない）は復旧可能なので許容する。
 *
 * 読み比べ: laravel-api の同名テストは 1 リクエストで完結するが、本アプリは
 * 確認 → 確定の 2 ステップのため、画像は一度 tmp/ に置かれてから本保存へ move される。
 *
 * 失敗の注入方法:
 * - DB 失敗   = Eloquent のモデルイベントで例外を投げる（モックではなく実フック）
 * - ファイル失敗 = Storage ファサードを差し替える（testing.md が許可する外部 I/O のモック）
 */
class TaskImageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    /** 確認ステップだけ実行し、tmp/ に画像を置いた状態にする */
    private function confirmCreateWithImage(User $user, string $title = '画像つき'): void
    {
        $this->actingAs($user)->post(route('tasks.store.confirm'), [
            'title' => $title,
            'image' => UploadedFile::fake()->create('p.jpg', 100, 'image/jpeg'),
        ])->assertOk();
    }

    /** 画像付きタスクを 2 ステップで作り、[タスク, 画像パス] を返す */
    private function taskWithImage(User $user, string $title = '元タスク'): array
    {
        $this->confirmCreateWithImage($user, $title);
        $this->actingAs($user)->post(route('tasks.store'))->assertRedirect();

        $task = $user->tasks()->latest('id')->firstOrFail();

        return [$task, $task->image_path];
    }

    /** copy / move だけが失敗するディスクへ差し替える */
    private function fakeDiskWhereWriteFails(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('copy')->andReturn(false);
        $disk->shouldReceive('move')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('uploads')->andReturn($disk);
    }

    // ---- 異常系: DB 失敗で孤児ファイルを残さない ----

    public function test_store_removes_moved_file_when_db_create_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->confirmCreateWithImage($user);

        Task::creating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->actingAs($user)->post(route('tasks.store'));

        $response->assertStatus(500);
        $this->assertSame(0, Task::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles(), '孤児ファイルが残ってはならない');
    }

    public function test_duplicate_removes_copied_file_when_db_create_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $original] = $this->taskWithImage($user);

        Task::creating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->actingAs($user)->post(route('tasks.duplicate', $task));

        $response->assertStatus(500);
        $this->assertSame(1, Task::count(), '複製は作成されない');
        $this->assertSame([$original], Storage::disk('uploads')->allFiles(), 'コピーした画像は補償削除される');
    }

    // ---- 異常系: ファイル操作失敗で DB に不正な path を保存しない ----

    public function test_store_does_not_create_task_when_image_move_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->confirmCreateWithImage($user);

        $this->fakeDiskWhereWriteFails();

        $response = $this->actingAs($user)->post(route('tasks.store'));

        $response->assertStatus(500);
        $this->assertSame(0, Task::count(), '移動失敗時にタスクを作ってはならない');
    }

    public function test_duplicate_does_not_create_task_when_image_copy_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task] = $this->taskWithImage($user);

        $this->fakeDiskWhereWriteFails();

        $response = $this->actingAs($user)->post(route('tasks.duplicate', $task));

        $response->assertStatus(500);
        $this->assertSame(1, Task::count(), 'コピー失敗時にタスクを作ってはならない');
    }

    // ---- 異常系: 画像差し替えが失敗しても旧画像を失わない ----

    public function test_update_keeps_old_image_when_db_update_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $old] = $this->taskWithImage($user);

        $this->actingAs($user)->post(route('tasks.update.confirm', $task), [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        Task::updating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->actingAs($user)->put(route('tasks.update', $task));

        $response->assertStatus(500);
        $this->assertSame($old, $task->fresh()->image_path, 'DB の image_path は据え置き');
        Storage::disk('uploads')->assertExists($old);
        $this->assertSame([$old], Storage::disk('uploads')->allFiles(), '新画像は補償削除され、旧画像だけが残る');
    }

    // ---- 異常系: 削除が失敗しても再試行できる状態を保つ ----

    public function test_destroy_keeps_task_and_image_when_db_delete_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $image] = $this->taskWithImage($user);

        Task::deleting(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->actingAs($user)->delete(route('tasks.destroy', $task));

        $response->assertStatus(500);
        $this->assertSame(1, Task::count());
        Storage::disk('uploads')->assertExists($image);
        $this->assertNotNull($task->fresh()->image_path, '再試行すれば削除できる状態を保つ');
    }

    // ---- 異常系: 例外を伴わない中断（モデルイベントが false を返す）----

    public function test_update_keeps_old_image_when_db_update_is_halted(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $old] = $this->taskWithImage($user);

        $this->actingAs($user)->post(route('tasks.update.confirm', $task), [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        // update() は中断されても例外を投げず false を返す。旧画像を消してはならない。
        Task::updating(fn () => false);

        $response = $this->actingAs($user)->put(route('tasks.update', $task));

        $response->assertStatus(500);
        $this->assertSame($old, $task->fresh()->image_path);
        Storage::disk('uploads')->assertExists($old);
        $this->assertSame([$old], Storage::disk('uploads')->allFiles());
    }

    public function test_destroy_keeps_image_when_db_delete_is_halted(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $image] = $this->taskWithImage($user);

        Task::deleting(fn () => false);

        $response = $this->actingAs($user)->delete(route('tasks.destroy', $task));

        $response->assertStatus(500);
        $this->assertSame(1, Task::count());
        Storage::disk('uploads')->assertExists($image);
    }

    // ---- 正常系: 退行していないこと ----

    public function test_update_deletes_old_image_only_after_db_commit(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $old] = $this->taskWithImage($user);

        $this->actingAs($user)->post(route('tasks.update.confirm', $task), [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ])->assertOk();
        $this->actingAs($user)->put(route('tasks.update', $task))->assertRedirect();

        $new = $task->fresh()->image_path;

        $this->assertNotSame($old, $new);
        Storage::disk('uploads')->assertExists($new);
        Storage::disk('uploads')->assertMissing($old);
        $this->assertSame([$new], Storage::disk('uploads')->allFiles());
    }

    public function test_destroy_removes_task_and_image(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        [$task, $image] = $this->taskWithImage($user);

        $this->actingAs($user)->delete(route('tasks.destroy', $task))->assertRedirect();

        $this->assertSame(0, Task::count());
        Storage::disk('uploads')->assertMissing($image);
    }
}
