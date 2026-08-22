<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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
 * 失敗の注入方法:
 * - DB 失敗   = Eloquent のモデルイベントで例外を投げる（モックではなく実フック）
 * - ファイル失敗 = Storage ファサードを差し替える（testing.md が許可する外部 I/O のモック）
 */
class TaskImageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** 画像付きタスクを 1 件作り、[タスク, 画像パス] を返す */
    private function taskWithImage(User $user, string $title = '元タスク'): array
    {
        $this->postJson('/api/tasks', [
            'title' => $title,
            'image' => UploadedFile::fake()->create('old.jpg', 100, 'image/jpeg'),
        ])->assertCreated();

        $task = $user->tasks()->latest('id')->firstOrFail();

        return [$task, $task->image_path];
    }

    /** copy だけが失敗するディスクへ差し替える */
    private function fakeDiskWhereCopyFails(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('copy')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('uploads')->andReturn($disk);
    }

    // ---- 異常系: DB 失敗で孤児ファイルを残さない ----

    public function test_store_removes_uploaded_file_when_db_create_fails(): void
    {
        Storage::fake('uploads');
        $this->actingUser();
        Task::creating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->postJson('/api/tasks', [
            'title' => '画像つき',
            'image' => UploadedFile::fake()->create('p.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, Task::count());
        $this->assertSame([], Storage::disk('uploads')->allFiles(), '孤児ファイルが残ってはならない');
    }

    public function test_duplicate_removes_copied_file_when_db_create_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $original] = $this->taskWithImage($user);

        Task::creating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->postJson("/api/tasks/{$task->id}/duplicate");

        $response->assertStatus(500);
        $this->assertSame(1, Task::count(), '複製は作成されない');
        $this->assertSame([$original], Storage::disk('uploads')->allFiles(), 'コピーした画像は補償削除される');
    }

    // ---- 異常系: ファイル操作失敗で DB に不正な path を保存しない ----

    public function test_duplicate_does_not_create_task_when_image_copy_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task] = $this->taskWithImage($user);

        $this->fakeDiskWhereCopyFails();

        $response = $this->postJson("/api/tasks/{$task->id}/duplicate");

        $response->assertStatus(500);
        $this->assertSame(1, Task::count(), 'コピー失敗時にタスクを作ってはならない');
    }

    // ---- 異常系: 画像差し替えが失敗しても旧画像を失わない ----

    public function test_update_keeps_old_image_when_db_update_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $old] = $this->taskWithImage($user);

        Task::updating(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(500);
        $this->assertSame($old, $task->fresh()->image_path, 'DB の image_path は据え置き');
        Storage::disk('uploads')->assertExists($old);
        $this->assertSame([$old], Storage::disk('uploads')->allFiles(), '新画像は補償削除され、旧画像だけが残る');
    }

    // ---- 異常系: 削除が失敗しても再試行できる状態を保つ ----

    public function test_destroy_keeps_task_and_image_when_db_delete_fails(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $image] = $this->taskWithImage($user);

        Task::deleting(fn () => throw new RuntimeException('DB 障害'));

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(500);
        $this->assertSame(1, Task::count());
        Storage::disk('uploads')->assertExists($image);
        $this->assertNotNull($task->fresh()->image_path, '再試行すれば削除できる状態を保つ');
    }

    // ---- 異常系: 例外を伴わない中断（モデルイベントが false を返す）----

    public function test_update_keeps_old_image_when_db_update_is_halted(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $old] = $this->taskWithImage($user);

        // update() は中断されても例外を投げず false を返す。旧画像を消してはならない。
        Task::updating(fn () => false);

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(500);
        $this->assertSame($old, $task->fresh()->image_path);
        Storage::disk('uploads')->assertExists($old);
        $this->assertSame([$old], Storage::disk('uploads')->allFiles());
    }

    public function test_destroy_keeps_image_when_db_delete_is_halted(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $image] = $this->taskWithImage($user);

        Task::deleting(fn () => false);

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(500);
        $this->assertSame(1, Task::count());
        Storage::disk('uploads')->assertExists($image);
    }

    // ---- 正常系: 退行していないこと ----

    public function test_update_deletes_old_image_only_after_db_commit(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $old] = $this->taskWithImage($user);

        $this->putJson("/api/tasks/{$task->id}", [
            'title' => '差し替え',
            'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $new = $task->fresh()->image_path;

        $this->assertNotSame($old, $new);
        Storage::disk('uploads')->assertExists($new);
        Storage::disk('uploads')->assertMissing($old);
        $this->assertSame([$new], Storage::disk('uploads')->allFiles());
    }

    public function test_destroy_removes_task_and_image(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        [$task, $image] = $this->taskWithImage($user);

        $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

        $this->assertSame(0, Task::count());
        Storage::disk('uploads')->assertMissing($image);
    }
}
