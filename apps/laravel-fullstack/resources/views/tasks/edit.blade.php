<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク編集 (laravel-fullstack)</title>
</head>
<body>
    <h1>タスク編集 <small>laravel-fullstack / Blade</small></h1>

    @if ($errors->any())
        <ul style="color:red">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('tasks.update', $task) }}">
        @csrf
        @method('PUT')
        <input type="text" name="title" value="{{ old('title', $task->title) }}">
        <button type="submit">更新</button>
    </form>

    <p><a href="{{ route('tasks.index') }}">一覧に戻る</a></p>
</body>
</html>
