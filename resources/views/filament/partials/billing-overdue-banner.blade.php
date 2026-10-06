@php($notice = \App\Filament\Pages\Billing::overdueNotice())

@if ($notice)
    <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200" role="alert">
        <span class="font-semibold">Subscription overdue.</span>
        {{ $notice }}
        @if (filament()->getTenant())
            <a href="{{ \App\Filament\Pages\Billing::getUrl() }}" class="font-semibold underline">Pay now</a>
        @endif
    </div>
@endif
