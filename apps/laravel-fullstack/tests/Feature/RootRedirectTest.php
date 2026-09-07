<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ルート URL（`/`）の入口としての挙動を検証する。
 *
 * fullstack は単独のトップページを持たず、`/` は常にタスク一覧へ送る（`routes/web.php`）。
 * 未ログインならそこから `auth` ミドルウェアがログイン画面へ誘導するため、
 * **ゲストの入口は 2 段のリダイレクトになる**。この経路が壊れるとアプリの入口が
 * 失われるが、他のテストはすべて `/tasks` 以下を直接叩くため検出できない。
 *
 * 元は Laravel 雛形の `ExampleTest` にこの検証だけが書き足されていた（issue #69）。
 * 「Example」という名前では何を守っているのか読み取れないため、意図が分かる名前へ移した。
 */
class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_task_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('tasks.index'));
    }

    public function test_guest_at_root_is_sent_to_login(): void
    {
        // `/` → `/tasks` → `/login`。入口をゲストで踏んだときに行き止まりにならないこと
        $this->get('/')->assertRedirect(route('tasks.index'));
        $this->get(route('tasks.index'))->assertRedirect(route('login'));
    }
}
