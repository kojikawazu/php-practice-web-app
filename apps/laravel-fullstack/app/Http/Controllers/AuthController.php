<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * セッション認証。登録（自動ログイン）・ログイン（セッション再生成）・ログアウト（セッション無効化）。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 2 — 「ログイン状態をどこに持つか」が 3 アプリで最も分かれる点）:
 * - laravel-api: 同名 AuthController。セッションを持たず、Hash::check() で手動照合してトークンを発行する。
 * - laminas: module/Application/src/Controller/AuthController.php。Auth::attempt() 相当がなく、
 *   PasswordHasher（自前 bcrypt）での照合と、AuthenticationService への identity 保存を自分で書く。
 */
class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create($data);
        Auth::login($user);

        return redirect()->route('tasks.index');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('tasks.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
