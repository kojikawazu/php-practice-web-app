<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

// 公開エンドポイント（未認証で叩けるため、レートリミットを必須にする）
// リミッタの定義は AppServiceProvider::boot()。login は email+IP で厳しく、IP で緩く数える。
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// トークン認証必須
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // トークン管理
    Route::get('/tokens', [TokenController::class, 'index']);
    // トークン発行は認証済みだが、漏えいしたトークンによる大量発行を抑えるため制限する
    Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:tokens');
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');

    Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate'])->whereNumber('task');
    Route::get('/tasks/{task}/image', [TaskController::class, 'image'])->whereNumber('task');
    Route::apiResource('tasks', TaskController::class);
});
