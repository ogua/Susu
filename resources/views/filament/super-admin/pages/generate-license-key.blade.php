<x-filament-panels::page>
    @if ($lastGenerated)
        <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">Generated key for install {{ $lastGenerated->install_id }}</p>
            <p class="mt-2 break-all rounded-lg bg-gray-100 p-3 font-mono text-xs dark:bg-gray-800">{{ $lastGenerated->license_key }}</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Expires {{ $lastGenerated->expires_at?->toFormattedDateString() }}
            </p>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
