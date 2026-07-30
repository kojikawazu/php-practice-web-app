<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * タスク（fs_tasks）。作成ユーザーに所有され、画像添付・URL の OGP プレビューを持つ。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 3）:
 * - laravel-api: app/Models/Task.php。同じ Eloquent だが、JSON 化を前提に $hidden / image_url アクセサを持つ。
 * - laminas: src/Model/Task.php（素の PHP クラス）+ src/Model/TaskTable.php（クエリ）。本クラスが $fillable /
 *   $casts の宣言だけで済ませている「型変換」と「DB アクセス」が、あちらでは自分で書くコードとして現れる。
 */
class Task extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'done', 'user_id', 'start_date', 'end_date', 'image_path', 'url', 'preview_title', 'preview_image'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'done' => false,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'done' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    /** @return BelongsTo<User, Task> 所有ユーザー */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
