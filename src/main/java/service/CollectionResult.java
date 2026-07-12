package service;

import models.JournalEntry;
import models.SavingsAccount;

public record CollectionResult(JournalEntry entry, SavingsAccount account, long commissionAmount, boolean duplicate) {
}
