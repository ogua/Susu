package service;

import models.GroupLoan;
import models.GroupLoanRepayment;
import models.JournalEntry;

public record GroupLoanRepaymentResult(JournalEntry entry, GroupLoan groupLoan, GroupLoanRepayment repayment,
                                        boolean duplicate) {
}
