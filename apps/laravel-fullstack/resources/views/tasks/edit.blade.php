<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>タスク編集 (laravel-fullstack)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-xl mx-auto p-6">
        <h1 class="text-2xl font-bold">タスク編集</h1>
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

        <form method="POST" action="{{ route('tasks.update.confirm', $task) }}" enctype="multipart/form-data" class="bg-white rounded-lg shadow p-4 space-y-3">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">タイトル</label>
                <input type="text" name="title" value="{{ old('title', $task->title) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">画像</label>
                @if ($task->image_path)
                    <img src="{{ route('tasks.image', $task) }}" alt="" class="w-24 h-24 object-cover rounded border mb-2">
                @endif
                <input type="file" name="image" accept="image/*" class="text-sm">
                <p class="text-xs text-gray-400 mt-1">選択すると差し替え（旧画像は削除されます）</p>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">URL（任意）</label>
                <input type="url" name="url" placeholder="https://example.com" value="{{ old('url', $task->url) }}" class="border rounded px-3 py-2 w-full">
                @if ($task->preview_title)
                    <p class="text-xs text-gray-500 mt-1">現在のプレビュー: {{ $task->preview_title }}</p>
                @endif
            </div>
            <div class="flex gap-3">
                <div class="flex-1">
                    <label class="block text-sm text-gray-600 mb-1">開始日</label>
                    <input type="text" class="flatpickr border rounded px-3 py-2 w-full" name="start_date" placeholder="開始日" value="{{ old('start_date', $task->start_date?->format('Y-m-d')) }}">
                </div>
                <div class="flex-1">
                    <label class="block text-sm text-gray-600 mb-1">終了日</label>
                    <input type="text" class="flatpickr border rounded px-3 py-2 w-full" name="end_date" placeholder="終了日" value="{{ old('end_date', $task->end_date?->format('Y-m-d')) }}">
                </div>
            </div>
            <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">更新</button>
        </form>

        <p class="mt-4"><a href="{{ route('tasks.index') }}" class="text-sm text-blue-600 hover:underline">← 一覧に戻る</a></p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script nonce="{{ $cspNonce }}">flatpickr('.flatpickr', { dateFormat: 'Y-m-d', allowInput: true });</script>
</body>
</html>
