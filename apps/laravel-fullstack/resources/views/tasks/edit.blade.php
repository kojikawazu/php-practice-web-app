<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク編集 (laravel-fullstack)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
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
        <input type="text" class="flatpickr" name="start_date" placeholder="開始日" value="{{ old('start_date', $task->start_date?->format('Y-m-d')) }}">
        <input type="text" class="flatpickr" name="end_date" placeholder="終了日" value="{{ old('end_date', $task->end_date?->format('Y-m-d')) }}">
        <button type="submit">更新</button>
    </form>

    <p><a href="{{ route('tasks.index') }}">一覧に戻る</a></p>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>flatpickr('.flatpickr', { dateFormat: 'Y-m-d', allowInput: true });</script>
</body>
</html>
