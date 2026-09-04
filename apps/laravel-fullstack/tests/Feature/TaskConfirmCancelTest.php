<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 確認画面のキャンセルが、保留中の操作と一時画像を実際に破棄することを検証する（issue #86）。
 *
 * 以前のキャンセルは一覧へ GET 遷移するだけで、セッションの保留ペイロードも tmp/ の
 * 画像も残っていた。表示上は「破棄されます」と書いてあるのに残るため、
 * **キャンセルしたはずの確定 POST を後から実行できてしまう**のが実害。
 * ここでは「キャンセル後に確定できないこと」を軸に据える（画面表示ではなく状態で見る）。
 */
class TaskConfirmCancelTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    /** 確認ステップだけ実行し、セッションと tmp/ に保留状態を作る */
    private function confirmCreateWithImage(User $user, string $title = '保留タスク'): void
    {
        $this->actingAs($user)->post(route('tasks.store.confirm'), [
            'title' => $title,
            'image' => UploadedFile::fake()->create('p.jpg', 100, 'image/jpeg'),
        ])->assertOk();
    }

    /** tmp/ に置かれた一時画像のパス（保留が無ければ null） */
    private function pendingTempPath(): ?string
    {
        $payload = session('task_confirm');

        return $payload['image_tmp'] ?? null;
    }

    // ---- 正常系 ----

    public function test_cancel_discards_pending_operation(): void
    {
        $user = $this->user();
        $this->confirmCreateWithImage($user, 'キャンセルされるタスク');

        $this->actingAs($user)->post(route('tasks.confirm.cancel'))
            ->assertRedirect(route('tasks.index'));

        // 保留が消えているので、確定 POST を投げても作成されない（一覧へ戻るだけ）
        $this->actingAs($user)->post(route('tasks.store'))->assertRedirect(route('tasks.index'));

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_cancel_deletes_the_pending_temp_image(): void
    {
        Storage::fake('uploads');

        $user = $this->user();
        $this->confirmCreateWithImage($user);

        $tmp = $this->pendingTempPath();
        $this->assertNotNull($tmp, '確認ステップで一時画像が置かれているはず');
        Storage::disk('uploads')->assertExists($tmp);

        $this->actingAs($user)->post(route('tasks.confirm.cancel'))->assertRedirect();

        // 以前はここが残り続け、次に確認画面を開くまで掃除されなかった
        Storage::disk('uploads')->assertMissing($tmp);
    }

    public function test_cancel_from_edit_returns_to_the_edit_screen(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '元のタイトル']);

        $this->actingAs($user)->post(route('tasks.update.confirm', $task), [
            'title' => '変更後のタイトル',
        ])->assertOk();

        // 戻り先は従来のリンクと同じ編集画面（一覧ではない）
        $this->actingAs($user)->post(route('tasks.confirm.cancel'))
            ->assertRedirect(route('tasks.edit', $task));

        // 保留が消えているので、確定 PUT を投げても更新されない
        $this->actingAs($user)->put(route('tasks.update', $task))->assertRedirect(route('tasks.index'));

        $this->assertSame('元のタイトル', $task->fresh()->title);
    }

    // ---- 準正常系 ----

    public function test_cancel_without_pending_operation_is_harmless(): void
    {
        $user = $this->user();

        // 二重送信・リロードでエラーにしない（冪等に扱う）
        $this->actingAs($user)->post(route('tasks.confirm.cancel'))
            ->assertRedirect(route('tasks.index'));
        $this->actingAs($user)->post(route('tasks.confirm.cancel'))
            ->assertRedirect(route('tasks.index'));

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_cancel_only_deletes_the_file_of_the_current_pending_operation(): void
    {
        Storage::fake('uploads');

        // 別の保留操作が残した一時画像に見立てたファイル（自セッションの保留ではない）
        Storage::disk('uploads')->put('tmp/other-pending.jpg', 'dummy');

        $user = $this->user();
        $this->confirmCreateWithImage($user);
        $tmp = $this->pendingTempPath();

        $this->actingAs($user)->post(route('tasks.confirm.cancel'))->assertRedirect();

        Storage::disk('uploads')->assertMissing($tmp);
        // 消すのはセッションが指す 1 件だけ。tmp/ を一括で掃除しない
        Storage::disk('uploads')->assertExists('tmp/other-pending.jpg');
    }

    // ---- 異常系 ----

    public function test_guest_cannot_cancel(): void
    {
        $this->post(route('tasks.confirm.cancel'))->assertRedirect(route('login'));
    }
}
