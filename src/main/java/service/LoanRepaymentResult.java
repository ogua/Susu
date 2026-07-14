package service;

import models.JournalEntry;
import models.Loan;

public record LoanRepaymentResult(JournalEntry entry, Loan loan, boolean duplicate) {
}
