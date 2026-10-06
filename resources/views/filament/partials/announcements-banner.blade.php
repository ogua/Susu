@php
    $user = filament()->auth()->user();
    $announcements = $user instanceof \App\Models\User && $user->company_id !== null
        ? \App\Models\Announcement::query()->visibleTo($user)->limit(3)->get()
        : collect();
@endphp

@foreach ($announcements as $announcement)
    @php
        $classes = match ($announcement->level) {
            \App\Enums\AnnouncementLevel::Critical => 'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200',
            \App\Enums\AnnouncementLevel::Warning => 'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200',
            default => 'border-info-300 bg-info-50 text-info-800 dark:border-info-700 dark:bg-info-950 dark:text-info-200',
        };
    @endphp
    <div class="mb-4 rounded-lg border p-4 text-sm {{ $classes }}" role="status">
        <span class="font-semibold">{{ $announcement->title }}</span>
        <span>{{ $announcement->body }}</span>
    </div>
@endforeach
