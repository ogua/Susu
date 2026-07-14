<?php

namespace App\Actions\Reports;

use App\Models\JournalEntry;
use App\Models\SavingsAccount;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonInterface;

/**
 * A running-balance statement of every ledger entry posted against a
 * savings account, scoped to its own ledger line only (an entry can carry
 * other unrelated lines, e.g. the agent-cash side of a collection).
 */
class GenerateAccountStatementPdfAction
{
    public function execute(SavingsAccount $account, ?CarbonInterface $from = null, ?CarbonInterface $to = null): PdfDocument
    {
        $account->loadMissing(['customer', 'product', 'branch']);

        $entries = $account->entries()
            ->when($from, fn ($query) => $query->where('recorded_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('recorded_at', '<=', $to))
            ->with(['lines' => fn ($query) => $query->where('ledger_account_id', $account->ledger_account_id)])
            ->orderBy('recorded_at')
            ->get();

        $running = 0;
        $rows = $entries->map(function (JournalEntry $entry) use (&$running): array {
            $line = $entry->lines->first();
            $running += $line->credit - $line->debit;

            return [
                'date' => $entry->recorded_at,
                'description' => $entry->description ?? $entry->type->name,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $running,
            ];
        });

        return Pdf::loadView('pdf.account-statement', [
            'account' => $account,
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'closingBalance' => $running,
        ]);
    }
}
