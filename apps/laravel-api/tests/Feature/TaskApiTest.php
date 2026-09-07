<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // ---- 正常系 ----

    public function test_index_returns_only_own_tasks(): void
    {
        $user = $this->actingUser();
        $user->tasks()->create(['title' => 'API タスク']);
        User::factory()->create()->tasks()->create(['title' => '他人のタスク']);

        $response = $this->getJson('/api/tasks');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['title' => 'API タスク']);
        $response->assertJsonMissing(['title' => '他人のタスク']);
    }

    public function test_index_paginates_with_meta(): void
    {
        $user = $this->actingUser();
        foreach (range(1, 7) as $n) {
            $user->tasks()->create(['title' => "タスク{$n}"]);
        }

        $page1 = $this->getJson('/api/tasks');
        $page1->assertOk();
        $page1->assertJsonCount(5, 'data');
        $page1->assertJsonFragment(['total' => 7, 'per_page' => 5, 'current_page' => 1]);

        $page2 = $this->getJson('/api/tasks?page=2');
        $page2->assertJsonCount(2, 'data');
    }

    public function test_index_respects_per_page(): void
    {
        $user = $this->actingUser();
        foreach (range(1, 4) as $n) {
            $user->tasks()->create(['title' => "タスク{$n}"]);
        }

        $response = $this->getJson('/api/tasks?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['per_page' => 2, 'total' => 4]);
    }

    public function test_index_clamps_per_page_over_max(): void
    {
        $this->actingUser();

        // per_page は max(1, min($n, 50)) でクランプ。上限 50 を超える指定は 50 に丸まる
        $response = $this->getJson('/api/tasks?per_page=999');

        $response->assertOk();
        $response->assertJsonFragment(['per_page' => 50]);
    }

    public function test_index_clamps_per_page_below_one(): void
    {
        $this->actingUser();

        // 0 以下の指定は最低 1 に丸まる
        $response = $this->getJson('/api/tasks?per_page=0');

        $response->assertOk();
        $response->assertJsonFragment(['per_page' => 1]);
    }

    public function test_index_search_filters_by_title(): void
    {
        $user = $this->actingUser();
        $user->tasks()->create(['title' => '買い物に行く']);
        $user->tasks()->create(['title' => '掃除をする']);

        $response = $this->getJson('/api/tasks?q='.urlencode('買い物'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['title' => '買い物に行く']);
        $response->assertJsonMissing(['title' => '掃除をする']);
    }

    public function test_store_creates_task_for_current_user(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/tasks', ['title' => '新規タスク']);

        $response->assertCreated();
        $response->assertJsonFragment(['title' => '新規タスク', 'done' => false]);
        $this->assertDatabaseHas('tasks', ['title' => '新規タスク', 'user_id' => $user->id]);
    }

    public function test_store_with_dates(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/tasks', [
            'title' => '期間付き',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-20',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['start_date' => '2026-06-10', 'end_date' => '2026-06-20']);
        $this->assertDatabaseHas('tasks', ['title' => '期間付き', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20']);
    }

    public function test_duplicate_creates_a_copy(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create(['title' => '元タスク', 'done' => true]);

        $response = $this->postJson("/api/tasks/{$task->id}/duplicate");

        $response->assertCreated();
        $response->assertJsonFragment(['title' => '元タスク（コピー）', 'done' => false]);
        $this->assertSame(2, $user->tasks()->count());
    }

    public function test_update_changes_own_task_title(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create(['title' => '旧タイトル']);

        $response = $this->putJson("/api/tasks/{$task->id}", ['title' => '新タイトル']);

        $response->assertOk();
        $response->assertJsonFragment(['title' => '新タイトル']);
        $this->assertSame('新タイトル', $task->fresh()->title);
    }

    // ---- 画像アップロード ----

    public function test_store_with_image_returns_image_url(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();

        $response = $this->post('/api/tasks', [
            'title' => '画像付き',
            'image' => UploadedFile::fake()->create('p.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('image_url'));
        $response->assertJsonMissingPath('image_path');
        $task = $user->tasks()->first();
        Storage::disk('uploads')->assertExists($task->image_path);
    }

    public function test_owner_can_fetch_image(): void
    {
        Storage::fake('uploads');
        $user = $this->actingUser();
        $this->post('/api/tasks', ['title' => 'x', 'image' => UploadedFile::fake()->create('p.png', 100, 'image/png')]);
        $task = $user->tasks()->first();

        $response = $this->get("/api/tasks/{$task->id}/image");

        $response->assertOk();
    }

    // ---- 準正常系・異常系 ----

    public function test_store_rejects_non_image(): void
    {
        Storage::fake('uploads');
        $this->actingUser();

        $response = $this->post('/api/tasks', [
            'title' => 'x',
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_cannot_fetch_other_users_image(): void
    {
        Storage::fake('uploads');
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => 'x', 'image_path' => 'whatever.jpg']);

        $response = $this->get("/api/tasks/{$othersTask->id}/image");

        $response->assertNotFound();
    }

    public function test_guest_cannot_list_tasks(): void
    {
        $response = $this->getJson('/api/tasks');

        $response->assertUnauthorized();
    }

    public function test_search_with_no_match_returns_empty(): void
    {
        $user = $this->actingUser();
        $user->tasks()->create(['title' => '買い物']);

        $response = $this->getJson('/api/tasks?q='.urlencode('存在しない'));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonFragment(['total' => 0]);
    }

    public function test_store_without_title_returns_422(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/tasks', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_store_with_too_long_title_returns_422(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/tasks', ['title' => str_repeat('x', 256)]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_store_rejects_end_date_before_start_date(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/tasks', [
            'title' => '逆転',
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-10',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('end_date');
    }

    public function test_store_rejects_invalid_date(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/tasks', ['title' => 'x', 'start_date' => 'not-a-date']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_date');
    }

    public function test_update_with_empty_title_returns_422(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create(['title' => '元のまま']);

        $response = $this->putJson("/api/tasks/{$task->id}", ['title' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
        $this->assertSame('元のまま', $task->fresh()->title);
    }

    public function test_cannot_update_other_users_task(): void
    {
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => '改ざん不可']);

        $response = $this->putJson("/api/tasks/{$othersTask->id}", ['title' => 'のっとり']);

        $response->assertNotFound();
        $this->assertSame('改ざん不可', $othersTask->fresh()->title);
    }

    public function test_cannot_view_other_users_task(): void
    {
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => '他人のタスク']);

        $response = $this->getJson("/api/tasks/{$othersTask->id}");

        $response->assertNotFound();
    }

    public function test_cannot_duplicate_other_users_task(): void
    {
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => '複製できない']);

        $response = $this->postJson("/api/tasks/{$othersTask->id}/duplicate");

        $response->assertNotFound();
        $this->assertDatabaseMissing('tasks', ['title' => '複製できない（コピー）']);
    }

    public function test_cannot_delete_other_users_task(): void
    {
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => '他人のタスク']);

        $response = $this->deleteJson("/api/tasks/{$othersTask->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $othersTask->id]);
    }

    // ---- 部分更新（PATCH）でのタスク期間の整合性 ----
    //
    // after_or_equal:start_date は「リクエストに両方の日付がある」前提のルールで、
    // 片側だけを送ると比較対象が消えて素通りする。更新後に確定する開始日・終了日を
    // 組み立ててから検証する（issue #83 / docs/07）。

    public function test_update_with_only_title_keeps_stored_dates(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '元のまま',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['title' => '改題']);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => '改題',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_update_accepts_start_date_within_stored_period(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '期間内へ移動',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['start_date' => '2026-06-18']);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => '2026-06-18',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_update_can_clear_start_date_with_null(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '開始日を消す',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['start_date' => null]);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => null,
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_update_rejects_start_date_after_stored_end_date(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '逆転させない',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['start_date' => '2026-06-25']);

        $response->assertStatus(422);
        // 送ったのは start_date なので、エラーもそのフィールドに載せる
        $response->assertJsonValidationErrors('start_date');
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_update_rejects_end_date_before_stored_start_date(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '逆転させない',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['end_date' => '2026-06-10']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('end_date');
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_update_rejects_end_date_before_start_date_when_both_sent(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create(['title' => '逆転させない']);

        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-10',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('end_date');
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => null,
            'end_date' => null,
        ]);
    }

    public function test_update_allows_start_date_after_stored_end_date_when_end_is_cleared(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '終了日ごと消す',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        // 終了日を消すなら、開始日が旧終了日より後でも矛盾しない
        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'start_date' => '2026-06-25',
            'end_date' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => '2026-06-25',
            'end_date' => null,
        ]);
    }

    public function test_update_allows_end_date_before_stored_start_date_when_start_is_cleared(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '開始日ごと消す',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'start_date' => null,
            'end_date' => '2026-06-10',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'start_date' => null,
            'end_date' => '2026-06-10',
        ]);
    }

    public function test_update_rejects_invalid_partial_date(): void
    {
        $user = $this->actingUser();
        $task = $user->tasks()->create([
            'title' => '不正な日付',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-20',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['start_date' => 'not-a-date']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_date');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'start_date' => '2026-06-15']);
    }
}
