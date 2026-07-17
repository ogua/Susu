package models;

/** One withdrawal request with account/customer context — Withdrawals report read model. */
public class WithdrawalRow {

    private final String requestedAt;
    private final String accountNumber;
    private final String customerName;
    private final String status;
    private final String requestedBy;
    private final String approvedBy;
    private final long penalty;
    private final long amount;

    public WithdrawalRow(String requestedAt, String accountNumber, String customerName, String status,
                         String requestedBy, String approvedBy, long penalty, long amount) {
        this.requestedAt = requestedAt;
        this.accountNumber = accountNumber;
        this.customerName = customerName;
        this.status = status;
        this.requestedBy = requestedBy;
        this.approvedBy = approvedBy;
        this.penalty = penalty;
        this.amount = amount;
    }

    public String getRequestedAt() { return requestedAt; }
    public String getAccountNumber() { return accountNumber; }
    public String getCustomerName() { return customerName; }
    public String getStatus() { return status; }
    public String getRequestedBy() { return requestedBy; }
    public String getApprovedBy() { return approvedBy; }
    public long getPenalty() { return penalty; }
    public long getAmount() { return amount; }
}
