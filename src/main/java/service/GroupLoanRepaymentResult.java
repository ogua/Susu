package service;

import models.GroupLoan;
import models.GroupLoanBorrower;
import models.JournalEntry;

public record GroupLoanRepaymentResult(JournalEntry entry, GroupLoan groupLoan, GroupLoanBorrower borrower, boolean duplicate) {
}
