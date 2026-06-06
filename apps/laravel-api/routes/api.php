<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

// 公開エンドポイント
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// トークン認証必須
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // トークン管理
    Route::get('/tokens', [TokenController::class, 'index']);
    Route::post('/tokens', [TokenController::class, 'store']);
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');

    Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate'])->whereNumber('task');
    Route::apiResource('tasks', TaskController::class);
});
