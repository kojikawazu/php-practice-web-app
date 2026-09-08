<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('tasks.index'));

// 未ログインのみアクセス可（認証画面）
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

// ログイン必須
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    // 新規登録: 確認 → 確定
    Route::post('/tasks/confirm', [TaskController::class, 'storeConfirm'])->name('tasks.store.confirm');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    // 確認画面のキャンセル。保留の破棄を伴うため GET ではなく POST + CSRF にする。
    Route::post('/tasks/confirm/cancel', [TaskController::class, 'cancelConfirm'])->name('tasks.confirm.cancel');
    Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
    Route::get('/tasks/{task}/image', [TaskController::class, 'image'])->name('tasks.image');
    // 編集: 確認 → 確定
    Route::post('/tasks/{task}/update-confirm', [TaskController::class, 'updateConfirm'])->name('tasks.update.confirm');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    // 複製: 確認 → 実行
    Route::get('/tasks/{task}/duplicate-confirm', [TaskController::class, 'duplicateConfirm'])->name('tasks.duplicate.confirm');
    Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate'])->name('tasks.duplicate');
    Route::patch('/tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
});
