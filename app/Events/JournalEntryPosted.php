<?php

namespace App\Events;

use App\Models\JournalEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalEntryPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public JournalEntry $entry) {}
}
