<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['title', 'done', 'user_id', 'start_date', 'end_date', 'image_path'];

    protected $attributes = [
        'done' => false,
    ];

    protected $casts = [
        'done' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    protected $hidden = ['user_id', 'image_path'];

    protected $appends = ['image_url'];

    /** 画像があれば認証付き配信エンドポイントの URL を返す（生パスは公開しない）*/
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? url("/api/tasks/{$this->id}/image") : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
