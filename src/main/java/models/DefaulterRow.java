package models;

/**
 * One overdue loan installment joined with its loan/customer/agent context —
 * a read model for the Defaulters report, not a table-backed entity.
 */
public class DefaulterRow {

    private final String loanNumber;
    private final String customerName;
    private final String customerPhone;
    private final String agentName;
    private final long daysOverdue;
    private final long remaining;

    public DefaulterRow(String loanNumber, String customerName, String customerPhone,
                         String agentName, long daysOverdue, long remaining) {
        this.loanNumber = loanNumber;
        this.customerName = customerName;
        this.customerPhone = customerPhone;
        this.agentName = agentName;
        this.daysOverdue = daysOverdue;
        this.remaining = remaining;
    }

    public String getLoanNumber() { return loanNumber; }
    public String getCustomerName() { return customerName; }
    public String getCustomerPhone() { return customerPhone; }
    public String getAgentName() { return agentName; }
    public long getDaysOverdue() { return daysOverdue; }
    public long getRemaining() { return remaining; }
}
