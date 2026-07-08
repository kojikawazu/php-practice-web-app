<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * タスク（api_tasks）。JSON では image_path（生パス）を隠し、代わりに仮想属性
 * image_url を出力する（$hidden / $appends + getImageUrlAttribute で実現）。
 */
class Task extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'done', 'user_id', 'start_date', 'end_date', 'image_path'];

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

    /** @var list<string> JSON 出力から隠すカラム（生の image_path は公開しない） */
    protected $hidden = ['user_id', 'image_path'];

    /** @var list<string> JSON 出力に加える仮想属性 */
    protected $appends = ['image_url'];

    /** 画像があれば認証付き配信エンドポイントの URL を返す（生パスは公開しない）*/
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? url("/api/tasks/{$this->id}/image") : null;
    }

    /** @return BelongsTo<User, Task> 所有ユーザー */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
