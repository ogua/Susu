<div class="space-y-3 text-sm">
    @forelse ($logs as $log)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="flex justify-between text-gray-500 dark:text-gray-400">
                <span>{{ $log->recipient }}</span>
                <span>{{ $log->created_at->format('d M Y H:i') }}</span>
            </div>
            <p class="mt-1 text-gray-800 dark:text-gray-200">{{ $log->body }}</p>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">No failed messages in this period.</p>
    @endforelse
</div>
