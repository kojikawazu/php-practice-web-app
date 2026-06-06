<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>確認 (laravel-fullstack)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-1">内容の確認</h1>
        <p class="text-xs text-gray-400 mb-4">laravel-fullstack / Blade</p>

        @php
            $isDuplicate = $mode === 'duplicate';
            $title = $isDuplicate ? $task->title : $payload['title'];
            $start = $isDuplicate ? $task->start_date?->format('Y-m-d') : ($payload['start_date'] ?? null);
            $end = $isDuplicate ? $task->end_date?->format('Y-m-d') : ($payload['end_date'] ?? null);
            $url = $isDuplicate ? $task->url : ($payload['url'] ?? null);
            $previewTitle = $isDuplicate ? $task->preview_title : ($payload['preview_title'] ?? null);
            $previewImage = $isDuplicate ? $task->preview_image : ($payload['preview_image'] ?? null);
        @endphp

        <div class="bg-white rounded-lg shadow p-4 space-y-3">
            <p class="text-sm text-gray-500">
                @switch($mode)
                    @case('create') 次の内容で<strong>登録</strong>します。よろしいですか？ @break
                    @case('edit') 次の内容で<strong>更新</strong>します。よろしいですか？ @break
                    @case('duplicate') 「{{ $title }}」を<strong>複製</strong>します。よろしいですか？ @break
                @endswitch
            </p>

            <div><span class="text-xs text-gray-500">タイトル</span><div class="font-medium">{{ $title }}</div></div>

            @if ($start || $end)
                <div><span class="text-xs text-gray-500">期間</span><div>{{ $start ?? '—' }} 〜 {{ $end ?? '—' }}</div></div>
            @endif

            @if (! $isDuplicate && ! empty($payload['image_name']))
                <div><span class="text-xs text-gray-500">添付画像</span><div>{{ $payload['image_name'] }}</div></div>
            @endif

            @if ($url)
                <div>
                    <span class="text-xs text-gray-500">URL プレビュー</span>
                    <div class="flex items-center gap-2 mt-1">
                        @if ($previewImage && \Illuminate\Support\Str::startsWith($previewImage, ['http://', 'https://']))
                            <img src="{{ $previewImage }}" alt="" referrerpolicy="no-referrer" class="w-12 h-12 object-cover rounded border">
                        @endif
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow" class="text-indigo-600 hover:underline text-sm">🔗 {{ $previewTitle ?? $url }}</a>
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 mt-4">
            @if ($mode === 'create')
                <form method="POST" action="{{ route('tasks.store') }}">
                    @csrf
                    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">この内容で登録</button>
                </form>
            @elseif ($mode === 'edit')
                <form method="POST" action="{{ route('tasks.update', $task) }}">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">この内容で更新</button>
                </form>
            @else
                <form method="POST" action="{{ route('tasks.duplicate', $task) }}">
                    @csrf
                    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">複製する</button>
                </form>
            @endif

            <a href="{{ $mode === 'edit' ? route('tasks.edit', $task) : route('tasks.index') }}" class="text-sm text-gray-500 hover:underline">キャンセル</a>
        </div>

        @if ($mode !== 'duplicate')
            <p class="text-xs text-gray-400 mt-3">※ キャンセルすると入力内容（添付画像を含む）は破棄されます。</p>
        @endif
    </div>
</body>
</html>
