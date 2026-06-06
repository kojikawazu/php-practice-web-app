<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_user_can_duplicate_own_task(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '元タスク', 'done' => true]);

        $response = $this->actingAs($user)->post(route('tasks.duplicate', $task));

        $response->assertRedirect(route('tasks.index'));
        $this->assertSame(2, $user->tasks()->count());
        $this->assertDatabaseHas('tasks', ['title' => '元タスク（コピー）', 'done' => false, 'user_id' => $user->id]);
    }

    public function test_store_with_dates(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->post(route('tasks.store'), [
            'title' => '期間付きタスク',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-20',
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'title' => '期間付きタスク',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_duplicate_copies_dates(): void
    {
        $user = $this->user();
        $user->tasks()->create(['title' => '原本', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20']);
        $task = $user->tasks()->first();

        $this->actingAs($user)->post(route('tasks.duplicate', $task));

        $this->assertDatabaseHas('tasks', [
            'title' => '原本（コピー）',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-20',
        ]);
    }

    public function test_index_paginates_at_5_per_page(): void
    {
        $user = $this->user();
        foreach (range(1, 7) as $n) {
            $user->tasks()->create(['title' => "タスク{$n}"]);
        }

        $page1 = $this->actingAs($user)->get(route('tasks.index'));
        $page1->assertOk();
        $this->assertCount(5, $page1->viewData('tasks'));

        $page2 = $this->actingAs($user)->get(route('tasks.index', ['page' => 2]));
        $this->assertCount(2, $page2->viewData('tasks'));
    }

    public function test_search_filters_by_title(): void
    {
        $user = $this->user();
        $user->tasks()->create(['title' => '買い物に行く']);
        $user->tasks()->create(['title' => '掃除をする']);

        $response = $this->actingAs($user)->get(route('tasks.index', ['q' => '買い物']));

        $response->assertOk();
        $response->assertSee('買い物に行く');
        $response->assertDontSee('掃除をする');
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

    public function test_cannot_duplicate_other_users_task(): void
    {
        $othersTask = $this->user()->tasks()->create(['title' => '複製できない']);

        $response = $this->actingAs($this->user())->post(route('tasks.duplicate', $othersTask));

        $response->assertNotFound();
        $this->assertDatabaseMissing('tasks', ['title' => '複製できない（コピー）']);
    }

    public function test_store_rejects_end_date_before_start_date(): void
    {
        $response = $this->actingAs($this->user())
            ->from(route('tasks.index'))
            ->post(route('tasks.store'), [
                'title' => '逆転期間',
                'start_date' => '2026-06-20',
                'end_date' => '2026-06-10',
            ]);

        $response->assertSessionHasErrors('end_date');
        $this->assertDatabaseMissing('tasks', ['title' => '逆転期間']);
    }

    public function test_store_rejects_invalid_date_format(): void
    {
        $response = $this->actingAs($this->user())
            ->from(route('tasks.index'))
            ->post(route('tasks.store'), [
                'title' => '不正日付',
                'start_date' => 'not-a-date',
            ]);

        $response->assertSessionHasErrors('start_date');
    }

    // ---- 画像アップロード ----

    public function test_store_with_image_saves_file(): void
    {
        Storage::fake('uploads');
        $user = $this->user();

        $response = $this->actingAs($user)->post(route('tasks.store'), [
            'title' => '画像付き',
            'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertRedirect(route('tasks.index'));
        $task = $user->tasks()->first();
        $this->assertNotNull($task->image_path);
        Storage::disk('uploads')->assertExists($task->image_path);
    }

    public function test_owner_can_view_image(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->actingAs($user)->post(route('tasks.store'), [
            'title' => '画像', 'image' => UploadedFile::fake()->create('p.png', 100, 'image/png'),
        ]);
        $task = $user->tasks()->first();

        $response = $this->actingAs($user)->get(route('tasks.image', $task));

        $response->assertOk();
    }

    public function test_update_replaces_image_and_deletes_old(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->actingAs($user)->post(route('tasks.store'), [
            'title' => '元', 'image' => UploadedFile::fake()->create('old.jpg', 100, 'image/jpeg'),
        ]);
        $task = $user->tasks()->first();
        $old = $task->image_path;

        $this->actingAs($user)->put(route('tasks.update', $task), [
            'title' => '元', 'image' => UploadedFile::fake()->create('new.jpg', 100, 'image/jpeg'),
        ]);

        $new = $task->fresh()->image_path;
        $this->assertNotSame($old, $new);
        Storage::disk('uploads')->assertMissing($old);
        Storage::disk('uploads')->assertExists($new);
    }

    public function test_duplicate_copies_image_to_new_file(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->actingAs($user)->post(route('tasks.store'), [
            'title' => '原本', 'image' => UploadedFile::fake()->create('o.jpg', 100, 'image/jpeg'),
        ]);
        $task = $user->tasks()->first();

        $this->actingAs($user)->post(route('tasks.duplicate', $task));

        $copy = $user->tasks()->where('title', '原本（コピー）')->first();
        $this->assertNotNull($copy->image_path);
        $this->assertNotSame($task->image_path, $copy->image_path);
        Storage::disk('uploads')->assertExists($copy->image_path);
    }

    // ---- 画像（異常系）----

    public function test_store_rejects_non_image_file(): void
    {
        Storage::fake('uploads');

        $response = $this->actingAs($this->user())
            ->from(route('tasks.index'))
            ->post(route('tasks.store'), [
                'title' => '不正ファイル',
                'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors('image');
        $this->assertDatabaseMissing('tasks', ['title' => '不正ファイル']);
    }

    public function test_cannot_view_other_users_image(): void
    {
        Storage::fake('uploads');
        $owner = $this->user();
        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => '他人画像', 'image' => UploadedFile::fake()->create('s.jpg', 100, 'image/jpeg'),
        ]);
        $task = $owner->tasks()->first();

        $response = $this->actingAs($this->user())->get(route('tasks.image', $task));

        $response->assertNotFound();
    }

    public function test_image_route_404_when_no_image(): void
    {
        $user = $this->user();
        $task = $user->tasks()->create(['title' => '画像なし']);

        $response = $this->actingAs($user)->get(route('tasks.image', $task));

        $response->assertNotFound();
    }

    public function test_search_with_no_match_shows_empty(): void
    {
        $user = $this->user();
        $user->tasks()->create(['title' => '買い物']);

        $response = $this->actingAs($user)->get(route('tasks.index', ['q' => '存在しないキーワード']));

        $response->assertOk();
        $response->assertSee('タスクはありません');
        $this->assertCount(0, $response->viewData('tasks'));
    }

    public function test_search_does_not_leak_other_users_matching_tasks(): void
    {
        $this->user()->tasks()->create(['title' => '秘密の買い物']);

        $response = $this->actingAs($this->user())->get(route('tasks.index', ['q' => '買い物']));

        $response->assertOk();
        $response->assertDontSee('秘密の買い物');
        $this->assertCount(0, $response->viewData('tasks'));
    }
}
