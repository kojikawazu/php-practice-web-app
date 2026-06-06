<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_index_search_filters_by_title(): void
    {
        $user = $this->actingUser();
        $user->tasks()->create(['title' => '買い物に行く']);
        $user->tasks()->create(['title' => '掃除をする']);

        $response = $this->getJson('/api/tasks?q=' . urlencode('買い物'));

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

    // ---- 準正常系・異常系 ----

    public function test_guest_cannot_list_tasks(): void
    {
        $response = $this->getJson('/api/tasks');

        $response->assertUnauthorized();
    }

    public function test_search_with_no_match_returns_empty(): void
    {
        $user = $this->actingUser();
        $user->tasks()->create(['title' => '買い物']);

        $response = $this->getJson('/api/tasks?q=' . urlencode('存在しない'));

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
}
