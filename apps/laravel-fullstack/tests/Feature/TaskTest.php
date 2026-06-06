<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    // ---- 正常系 ----

    public function test_index_displays_only_own_tasks(): void
    {
        $user = $this->user();
        $user->tasks()->create(['title' => '牛乳を買う']);

        $response = $this->actingAs($user)->get(route('tasks.index'));

        $response->assertOk();
        $response->assertSee('牛乳を買う');
    }

    public function test_store_creates_task_owned_by_current_user(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->post(route('tasks.store'), ['title' => '部屋を掃除する']);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', ['title' => '部屋を掃除する', 'user_id' => $user->id]);
    }

    public function test_toggle_marks_own_task_done(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '完了させる']);

        $this->actingAs($user)->patch(route('tasks.toggle', $task));

        $this->assertTrue($task->fresh()->done);
    }

    public function test_user_can_update_own_task_title(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '旧タイトル']);

        $response = $this->actingAs($user)->put(route('tasks.update', $task), ['title' => '新タイトル']);

        $response->assertRedirect(route('tasks.index'));
        $this->assertSame('新タイトル', $task->fresh()->title);
    }

    public function test_user_can_open_edit_form_of_own_task(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '編集対象']);

        $response = $this->actingAs($user)->get(route('tasks.edit', $task));

        $response->assertOk();
        $response->assertSee('編集対象');
    }

    // ---- 準正常系・異常系 ----

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('tasks.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_index_hides_other_users_tasks(): void
    {
        $owner = $this->user();
        $owner->tasks()->create(['title' => '他人の秘密タスク']);

        $response = $this->actingAs($this->user())->get(route('tasks.index'));

        $response->assertOk();
        $response->assertDontSee('他人の秘密タスク');
    }

    public function test_store_rejects_empty_title(): void
    {
        $response = $this->actingAs($this->user())
            ->from(route('tasks.index'))
            ->post(route('tasks.store'), ['title' => '']);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_cannot_toggle_other_users_task(): void
    {
        $othersTask = $this->user()->tasks()->create(['title' => '触れないタスク']);

        $response = $this->actingAs($this->user())->patch(route('tasks.toggle', $othersTask));

        $response->assertNotFound();
        $this->assertFalse($othersTask->fresh()->done);
    }

    public function test_cannot_destroy_other_users_task(): void
    {
        $othersTask = $this->user()->tasks()->create(['title' => '消せないタスク']);

        $response = $this->actingAs($this->user())->delete(route('tasks.destroy', $othersTask));

        $response->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $othersTask->id]);
    }

    public function test_update_rejects_empty_title(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '元のまま']);

        $response = $this->actingAs($user)
            ->from(route('tasks.edit', $task))
            ->put(route('tasks.update', $task), ['title' => '']);

        $response->assertSessionHasErrors('title');
        $this->assertSame('元のまま', $task->fresh()->title);
    }

    public function test_cannot_update_other_users_task(): void
    {
        $othersTask = $this->user()->tasks()->create(['title' => '改ざん不可']);

        $response = $this->actingAs($this->user())
            ->put(route('tasks.update', $othersTask), ['title' => 'のっとり']);

        $response->assertNotFound();
        $this->assertSame('改ざん不可', $othersTask->fresh()->title);
    }

    public function test_cannot_open_edit_form_of_other_users_task(): void
    {
        $othersTask = $this->user()->tasks()->create(['title' => '覗けない']);

        $response = $this->actingAs($this->user())->get(route('tasks.edit', $othersTask));

        $response->assertNotFound();
    }
}
