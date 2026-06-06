<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>新規登録 (laravel-fullstack)</title>
</head>
<body>
    <h1>新規登録</h1>

    @if ($errors->any())
        <ul style="color:red">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <p><input type="text" name="name" placeholder="名前" value="{{ old('name') }}"></p>
        <p><input type="email" name="email" placeholder="メールアドレス" value="{{ old('email') }}"></p>
        <p><input type="password" name="password" placeholder="パスワード（8文字以上）"></p>
        <p><input type="password" name="password_confirmation" placeholder="パスワード（確認）"></p>
        <button type="submit">登録</button>
    </form>

    <p>すでにアカウントがある場合は <a href="{{ route('login') }}">ログイン</a></p>
</body>
</html>
