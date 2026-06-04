<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    // ---- 正常系 ----

    public function test_index_returns_tasks_as_json(): void
    {
        Task::create(['title' => 'API タスク']);

        $response = $this->getJson('/api/tasks');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['title' => 'API タスク']);
    }

    public function test_store_creates_task_and_returns_201(): void
    {
        $response = $this->postJson('/api/tasks', ['title' => '新規タスク']);

        $response->assertCreated();
        $response->assertJsonFragment(['title' => '新規タスク', 'done' => false]);
        $this->assertDatabaseHas('tasks', ['title' => '新規タスク']);
    }

    // ---- 準正常系・異常系 ----

    public function test_store_without_title_returns_422(): void
    {
        $response = $this->postJson('/api/tasks', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_store_with_too_long_title_returns_422(): void
    {
        $response = $this->postJson('/api/tasks', ['title' => str_repeat('x', 256)]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_store_with_non_boolean_done_returns_422(): void
    {
        $response = $this->postJson('/api/tasks', ['title' => 'ok', 'done' => 'maybe']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('done');
    }

    public function test_show_unknown_task_returns_404(): void
    {
        $response = $this->getJson('/api/tasks/999999');

        $response->assertNotFound();
    }
}
