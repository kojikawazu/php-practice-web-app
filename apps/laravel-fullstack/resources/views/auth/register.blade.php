<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>新規登録 (laravel-fullstack)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-sm mx-auto p-6 mt-10">
        <h1 class="text-2xl font-bold mb-4">新規登録</h1>

        @if ($errors->any())
            <div class="bg-red-100 text-red-700 rounded p-3 mb-4 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="bg-white rounded-lg shadow p-4 space-y-3">
            @csrf
            <input type="text" name="name" placeholder="名前" value="{{ old('name') }}" class="border rounded px-3 py-2 w-full">
            <input type="email" name="email" placeholder="メールアドレス" value="{{ old('email') }}" class="border rounded px-3 py-2 w-full">
            <input type="password" name="password" placeholder="パスワード（8文字以上）" class="border rounded px-3 py-2 w-full">
            <input type="password" name="password_confirmation" placeholder="パスワード（確認）" class="border rounded px-3 py-2 w-full">
            <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 w-full hover:bg-blue-700">登録</button>
        </form>

        <p class="mt-4 text-sm text-gray-600">すでにアカウントがある場合は <a href="{{ route('login') }}" class="text-blue-600 hover:underline">ログイン</a></p>
    </div>
</body>
</html>
