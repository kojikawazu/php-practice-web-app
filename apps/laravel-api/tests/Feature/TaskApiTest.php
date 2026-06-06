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
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['title' => 'API タスク']);
        $response->assertJsonMissing(['title' => '他人のタスク']);
    }

    public function test_store_creates_task_for_current_user(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/tasks', ['title' => '新規タスク']);

        $response->assertCreated();
        $response->assertJsonFragment(['title' => '新規タスク', 'done' => false]);
        $this->assertDatabaseHas('tasks', ['title' => '新規タスク', 'user_id' => $user->id]);
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

    public function test_cannot_delete_other_users_task(): void
    {
        $this->actingUser();
        $othersTask = User::factory()->create()->tasks()->create(['title' => '他人のタスク']);

        $response = $this->deleteJson("/api/tasks/{$othersTask->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $othersTask->id]);
    }
}
