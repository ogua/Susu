package models;

/** One loan with customer/agent context — Loan Portfolio report read model. */
public class LoanPortfolioRow {

    private final String loanNumber;
    private final String customerName;
    private final String agentName;
    private final String status;
    private final String appliedAt;
    private final String disbursedAt;
    private final long principal;
    private final long outstanding;

    public LoanPortfolioRow(String loanNumber, String customerName, String agentName, String status,
                            String appliedAt, String disbursedAt, long principal, long outstanding) {
        this.loanNumber = loanNumber;
        this.customerName = customerName;
        this.agentName = agentName;
        this.status = status;
        this.appliedAt = appliedAt;
        this.disbursedAt = disbursedAt;
        this.principal = principal;
        this.outstanding = outstanding;
    }

    public String getLoanNumber() { return loanNumber; }
    public String getCustomerName() { return customerName; }
    public String getAgentName() { return agentName; }
    public String getStatus() { return status; }
    public String getAppliedAt() { return appliedAt; }
    public String getDisbursedAt() { return disbursedAt; }
    public long getPrincipal() { return principal; }
    public long getOutstanding() { return outstanding; }
}
