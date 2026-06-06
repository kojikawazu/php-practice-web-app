<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク一覧 (laravel-fullstack)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
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
        <input type="text" class="flatpickr" name="start_date" placeholder="開始日" value="{{ old('start_date') }}">
        <input type="text" class="flatpickr" name="end_date" placeholder="終了日" value="{{ old('end_date') }}">
        <button type="submit">追加</button>
    </form>

    <form method="GET" action="{{ route('tasks.index') }}">
        <input type="text" name="q" placeholder="タイトルで検索" value="{{ $q }}">
        <button type="submit">検索</button>
        @if ($q !== '')
            <a href="{{ route('tasks.index') }}">クリア</a>
        @endif
    </form>

    <ul>
        @forelse ($tasks as $task)
            <li>
                <span style="{{ $task->done ? 'text-decoration:line-through' : '' }}">
                    {{ $task->title }}
                </span>
                @if ($task->start_date || $task->end_date)
                    <small>[{{ $task->start_date?->format('Y-m-d') ?? '—' }} 〜 {{ $task->end_date?->format('Y-m-d') ?? '—' }}]</small>
                @endif
                <a href="{{ route('tasks.edit', $task) }}">編集</a>
                <form method="POST" action="{{ route('tasks.duplicate', $task) }}" style="display:inline">
                    @csrf
                    <button type="submit">複製</button>
                </form>
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

    {{ $tasks->links() }}

    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>flatpickr('.flatpickr', { dateFormat: 'Y-m-d', allowInput: true });</script>
</body>
</html>
