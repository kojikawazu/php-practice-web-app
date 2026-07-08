<?php

namespace Tests\Unit;

use App\Models\Task;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Task モデルの単体挙動（casts / 仮想属性 / シリアライズ隠蔽）。
 *
 * DB もアプリも起動しない純粋ユニット。url() を伴う image_url の生成分岐だけは
 * アプリが必要なため、そこは Feature（TaskApiTest）のカバレッジに委ねる。
 */
class TaskModelTest extends TestCase
{
    // ---- 正常系 ----

    public function test_title_is_mass_assignable(): void
    {
        $task = new Task(['title' => '牛乳を買う']);

        $this->assertSame('牛乳を買う', $task->title);
    }

    public function test_done_is_cast_to_boolean(): void
    {
        $task = new Task;
        $task->done = 1;

        $this->assertTrue($task->done);
        $this->assertIsBool($task->done);
    }

    public function test_dates_are_cast_to_carbon_ymd(): void
    {
        $task = new Task;
        $task->start_date = '2026-06-10';

        $this->assertInstanceOf(Carbon::class, $task->start_date);
        $this->assertSame('2026-06-10', $task->start_date->format('Y-m-d'));
    }

    public function test_hidden_and_appends_are_configured(): void
    {
        $task = new Task;

        $this->assertEqualsCanonicalizing(['user_id', 'image_path'], $task->getHidden());
        $this->assertSame(['image_url'], $task->getAppends());
    }

    // ---- 準正常系・異常系 ----

    public function test_done_defaults_to_false(): void
    {
        $task = new Task;

        $this->assertFalse($task->done);
    }

    public function test_done_string_zero_is_false(): void
    {
        $task = new Task;
        $task->done = '0';

        $this->assertFalse($task->done);
    }

    public function test_image_url_is_null_when_no_image(): void
    {
        // image_path が無ければアクセサは url() を呼ばず即 null を返す
        $task = new Task;

        $this->assertNull($task->image_url);
    }

    public function test_to_array_hides_raw_image_path_and_user_id(): void
    {
        $task = new Task(['title' => 'x']);
        $task->user_id = 5;
        $task->image_path = null; // url() を発火させないため null のまま

        $array = $task->toArray();

        $this->assertArrayNotHasKey('user_id', $array);
        $this->assertArrayNotHasKey('image_path', $array);
        $this->assertArrayHasKey('image_url', $array);
        $this->assertNull($array['image_url']);
    }

    public function test_non_fillable_id_is_not_mass_assigned(): void
    {
        $task = new Task(['id' => 999, 'title' => 'x']);

        $this->assertNull($task->id);
    }
}
