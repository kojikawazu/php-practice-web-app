<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク一覧 (laravel-fullstack)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-3xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <span class="text-sm text-gray-600">{{ Auth::user()->name }} さん</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-800 hover:underline">ログアウト</button>
            </form>
        </div>

        <h1 class="text-2xl font-bold">タスク一覧</h1>
        <p class="text-xs text-gray-400 mb-4">laravel-fullstack / Blade</p>

        @if ($errors->any())
            <div class="bg-red-100 text-red-700 rounded p-3 mb-4 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('tasks.store') }}" enctype="multipart/form-data" class="bg-white rounded-lg shadow p-4 mb-4 flex flex-wrap gap-2 items-center">
            @csrf
            <input type="text" name="title" placeholder="タスク名" value="{{ old('title') }}" class="border rounded px-3 py-2 flex-1 min-w-40">
            <input type="text" class="flatpickr border rounded px-3 py-2 w-32" name="start_date" placeholder="開始日" value="{{ old('start_date') }}">
            <input type="text" class="flatpickr border rounded px-3 py-2 w-32" name="end_date" placeholder="終了日" value="{{ old('end_date') }}">
            <input type="file" name="image" accept="image/*" class="text-sm">
            <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">追加</button>
        </form>

        <form method="GET" action="{{ route('tasks.index') }}" class="flex gap-2 items-center mb-4">
            <input type="text" name="q" placeholder="タイトルで検索" value="{{ $q }}" class="border rounded px-3 py-2 flex-1">
            <button type="submit" class="bg-gray-600 text-white rounded px-4 py-2 hover:bg-gray-700">検索</button>
            @if ($q !== '')
                <a href="{{ route('tasks.index') }}" class="text-sm text-blue-600 hover:underline">クリア</a>
            @endif
        </form>

        <ul class="space-y-2">
            @forelse ($tasks as $task)
                <li class="bg-white rounded-lg shadow p-3 flex flex-wrap items-center gap-3">
                    @if ($task->image_path)
                        <img src="{{ route('tasks.image', $task) }}" alt="" class="w-10 h-10 object-cover rounded border">
                    @endif
                    <span class="flex-1 {{ $task->done ? 'line-through text-gray-400' : '' }}">{{ $task->title }}</span>
                    @if ($task->start_date || $task->end_date)
                        <span class="text-xs text-gray-500">[{{ $task->start_date?->format('Y-m-d') ?? '—' }} 〜 {{ $task->end_date?->format('Y-m-d') ?? '—' }}]</span>
                    @endif
                    <a href="{{ route('tasks.edit', $task) }}" class="text-sm text-blue-600 hover:underline">編集</a>
                    <form method="POST" action="{{ route('tasks.duplicate', $task) }}">
                        @csrf
                        <button type="submit" class="text-sm text-blue-600 hover:underline">複製</button>
                    </form>
                    <form method="POST" action="{{ route('tasks.toggle', $task) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="text-sm text-green-600 hover:underline">{{ $task->done ? '未完了に戻す' : '完了' }}</button>
                    </form>
                    <form method="POST" action="{{ route('tasks.destroy', $task) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">削除</button>
                    </form>
                </li>
            @empty
                <li class="text-gray-500">タスクはありません。</li>
            @endforelse
        </ul>

        <div class="mt-4">
            {{ $tasks->links() }}
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>flatpickr('.flatpickr', { dateFormat: 'Y-m-d', allowInput: true });</script>
</body>
</html>
