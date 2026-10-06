<x-filament-panels::page>
    @php
        $subscription = $this->subscription();
        $usage = $this->usage();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Current plan</x-slot>

        @if ($subscription === null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Your company is not on a subscription plan yet. Contact the SusuApp team to choose one.
            </p>
        @else
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Plan</dt>
                    <dd class="text-base font-semibold">{{ $subscription->plan->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Price</dt>
                    <dd class="text-base font-semibold">
                        {{ \App\Support\Money::format($subscription->plan->price_amount, $subscription->plan->currency) }}
                        / {{ $subscription->plan->billing_period->value === 'yearly' ? 'year' : 'month' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                    <dd>
                        <x-filament::badge :color="match ($subscription->status) {
                            \App\Enums\SubscriptionStatus::Active => 'success',
                            \App\Enums\SubscriptionStatus::Trialing => 'info',
                            \App\Enums\SubscriptionStatus::PastDue => 'danger',
                            default => 'gray',
                        }">
                            {{ str_replace('_', ' ', ucfirst($subscription->status->value)) }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $subscription->trial_ends_at ? 'Trial ends' : 'Renews' }}
                    </dt>
                    <dd class="text-base font-semibold">
                        {{ ($subscription->trial_ends_at ?? $subscription->current_period_end)?->format('d M Y') ?? '—' }}
                    </dd>
                </div>
            </dl>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Usage</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($usage as $resource => $figures)
                @php
                    $percent = $figures['limit'] ? min(100, (int) round($figures['used'] / max(1, $figures['limit']) * 100)) : null;
                @endphp
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-300">{{ ucfirst($resource) }}</span>
                        <span class="font-medium">{{ $figures['used'] }} / {{ $figures['limit'] ?? 'Unlimited' }}</span>
                    </div>
                    @if ($percent !== null)
                        <div class="mt-1 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                class="h-2 rounded-full {{ $percent >= 90 ? 'bg-danger-500' : 'bg-primary-500' }}"
                                style="width: {{ $percent }}%"
                            ></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{ $this->table }}

    @if (config('platform.support_email') || config('platform.support_phone'))
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Questions about your subscription? Contact the SusuApp team
            @if (config('platform.support_email'))
                at <a href="mailto:{{ config('platform.support_email') }}" class="underline">{{ config('platform.support_email') }}</a>
            @endif
            @if (config('platform.support_phone'))
                or {{ config('platform.support_phone') }}
            @endif
            .
        </p>
    @endif
</x-filament-panels::page>
