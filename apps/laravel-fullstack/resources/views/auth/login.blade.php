<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン (laravel-fullstack)</title>
</head>
<body>
    <h1>ログイン</h1>

    @if ($errors->any())
        <ul style="color:red">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <p><input type="email" name="email" placeholder="メールアドレス" value="{{ old('email') }}"></p>
        <p><input type="password" name="password" placeholder="パスワード"></p>
        <p><label><input type="checkbox" name="remember"> ログイン状態を保持</label></p>
        <button type="submit">ログイン</button>
    </form>

    <p>アカウントがない場合は <a href="{{ route('register') }}">新規登録</a></p>
</body>
</html>
