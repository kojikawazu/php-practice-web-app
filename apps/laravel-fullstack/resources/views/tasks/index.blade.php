<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク一覧 (laravel-fullstack)</title>
</head>
<body>
    <p>
        {{ Auth::user()->name }} さん
        <form method="POST" action="{{ route('logout') }}" style="display:inline">
            @csrf
            <button type="submit">ログアウト</button>
        </form>
    </p>

    <h1>タスク一覧 <small>laravel-fullstack / Blade</small></h1>

    @if ($errors->any())
        <ul style="color:red">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('tasks.store') }}">
        @csrf
        <input type="text" name="title" placeholder="タスク名" value="{{ old('title') }}">
        <button type="submit">追加</button>
    </form>

    <ul>
        @forelse ($tasks as $task)
            <li>
                <span style="{{ $task->done ? 'text-decoration:line-through' : '' }}">
                    {{ $task->title }}
                </span>
                <a href="{{ route('tasks.edit', $task) }}">編集</a>
                <form method="POST" action="{{ route('tasks.toggle', $task) }}" style="display:inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit">{{ $task->done ? '未完了に戻す' : '完了' }}</button>
                </form>
                <form method="POST" action="{{ route('tasks.destroy', $task) }}" style="display:inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit">削除</button>
                </form>
            </li>
        @empty
            <li>タスクはありません。</li>
        @endforelse
    </ul>
</body>
</html>
