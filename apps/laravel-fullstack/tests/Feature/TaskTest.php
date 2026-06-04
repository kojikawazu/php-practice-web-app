<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    // ---- 正常系 ----

    public function test_index_displays_existing_tasks(): void
    {
        Task::create(['title' => '牛乳を買う']);

        $response = $this->get(route('tasks.index'));

        $response->assertOk();
        $response->assertSee('牛乳を買う');
    }

    public function test_store_creates_a_task_and_redirects(): void
    {
        $response = $this->post(route('tasks.store'), ['title' => '部屋を掃除する']);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', ['title' => '部屋を掃除する', 'done' => false]);
    }

    public function test_toggle_marks_task_done(): void
    {
        $task = Task::create(['title' => '完了させる']);

        $this->patch(route('tasks.toggle', $task));

        $this->assertTrue($task->fresh()->done);
    }

    // ---- 準正常系・異常系 ----

    public function test_store_rejects_empty_title(): void
    {
        $response = $this->from(route('tasks.index'))
            ->post(route('tasks.store'), ['title' => '']);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_store_rejects_title_longer_than_255(): void
    {
        $response = $this->from(route('tasks.index'))
            ->post(route('tasks.store'), ['title' => str_repeat('あ', 256)]);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_toggle_unknown_task_returns_404(): void
    {
        $response = $this->patch(route('tasks.toggle', 999999));

        $response->assertNotFound();
    }

    public function test_destroy_unknown_task_returns_404(): void
    {
        $response = $this->delete(route('tasks.destroy', 999999));

        $response->assertNotFound();
    }
}
