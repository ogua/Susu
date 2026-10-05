@php
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-5 md:items-end">
            <label class="block">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Branch</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="branchId">
                        @foreach ($this->branchOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Repayment date</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input type="date" wire:model="date" max="{{ now()->toDateString() }}" />
                </x-filament::input.wrapper>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Customer group</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model="loanGroupId">
                        <option value="">All groups</option>
                        @foreach ($this->groupOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Loan officer</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model="officerId">
                        <option value="">All staff</option>
                        @foreach ($this->officerOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <x-filament::button wire:click="loadSheet" icon="heroicon-o-magnifying-glass">
                Load sheet
            </x-filament::button>
        </div>
    </x-filament::section>

    @if ($loaded)
        <x-filament::section>
            <x-slot name="heading">
                {{ $this->branchOptions()[$branchId] ?? '' }}
                · {{ $loanGroupId ? ($this->groupOptions()[$loanGroupId] ?? '') : 'All groups' }}
                · {{ $officerId ? ($this->officerOptions()[$officerId] ?? '') : 'All staff' }}
                · {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}
            </x-slot>

            @if ($rows === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">Nobody is due on this date for the selected filters.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-600 dark:border-white/10 dark:text-gray-300">
                                <th class="px-2 py-2 font-semibold">Loan #</th>
                                <th class="px-2 py-2 font-semibold">Product</th>
                                <th class="px-2 py-2 font-semibold">Client</th>
                                <th class="px-2 py-2 text-right font-semibold">Balance</th>
                                <th class="px-2 py-2 text-right font-semibold">Due</th>
                                <th class="px-2 py-2 text-right font-semibold">Overdue</th>
                                <th class="px-2 py-2 font-semibold" style="min-width: 9rem">Repayment (GHS)</th>
                                <th class="px-2 py-2 font-semibold">Savings account</th>
                                <th class="px-2 py-2 text-right font-semibold">Savings balance</th>
                                <th class="px-2 py-2 font-semibold" style="min-width: 9rem">Deposit (GHS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $index => $row)
                                <tr wire:key="sheet-row-{{ $row['key'] }}" class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-2 py-2 font-mono">{{ $row['loan_number'] ?? '—' }}</td>
                                    <td class="px-2 py-2">{{ $row['product'] ?? '—' }}</td>
                                    <td class="px-2 py-2">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['customer_name'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $row['phone'] }}</div>
                                    </td>
                                    <td class="px-2 py-2 text-right">{{ $row['loan_id'] ? Money::format($row['outstanding']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right">{{ $row['loan_id'] ? Money::format($row['amount_due']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right {{ $row['overdue'] > 0 ? 'font-semibold text-danger-600' : '' }}">
                                        {{ $row['loan_id'] ? Money::format($row['overdue']) : '—' }}
                                    </td>
                                    <td class="px-2 py-2">
                                        @if ($row['loan_id'])
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="number" step="0.01" min="0" wire:model.blur="rows.{{ $index }}.repayment" placeholder="0.00" />
                                            </x-filament::input.wrapper>
                                            @if ($row['amount_due'] > 0)
                                                <x-filament::link tag="button" size="sm" wire:click="fillDue({{ $index }})" class="mt-1">
                                                    Paid in full ({{ Money::format($row['amount_due']) }})
                                                </x-filament::link>
                                            @endif
                                        @else
                                            <span class="text-gray-400">No active loan</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 font-mono">{{ $row['savings_account_number'] ?? '—' }}</td>
                                    <td class="px-2 py-2 text-right">{{ $row['savings_account_id'] ? Money::format($row['savings_balance']) : '—' }}</td>
                                    <td class="px-2 py-2">
                                        @if ($row['savings_account_id'])
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="number" step="0.01" min="0" wire:model.blur="rows.{{ $index }}.deposit"
                                                    placeholder="× {{ number_format($row['contribution_amount'] / 100, 2) }}" />
                                            </x-filament::input.wrapper>
                                        @else
                                            <span class="text-gray-400">No account</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold text-gray-950 dark:text-white">
                                <td colspan="6" class="px-2 py-3 text-right">Totals ({{ count($rows) }} clients)</td>
                                <td class="px-2 py-3">{{ Money::format($this->totalRepayment()) }}</td>
                                <td colspan="2"></td>
                                <td class="px-2 py-3">{{ Money::format($this->totalDeposit()) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="paymentMethod">
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile money</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <x-filament::button color="gray" wire:click="loadSheet">Reset</x-filament::button>
                    <x-filament::button wire:click="submit" wire:confirm="Post {{ Money::format($this->totalRepayment()) }} in repayments and {{ Money::format($this->totalDeposit()) }} in deposits from {{ $this->payingCount() }} of {{ count($rows) }} client(s)? Only post money actually collected.">
                        Submit
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
