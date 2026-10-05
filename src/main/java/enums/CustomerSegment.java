package enums;

/**
 * The customer-list segments — mirrors the backend's App\Enums\CustomerSegment
 * so the desktop list counts customers the same way the web tabs do.
 * "Pending" = active but no savings account opened yet (there is no
 * customer-approval workflow). Each segment is a SQL condition on the
 * {@code customers} table.
 */
public enum CustomerSegment {
    ALL("All", "1 = 1"),
    ACTIVE("Active", "customers.status = 'active'"),
    PENDING("Pending (no account)", "customers.status = 'active'"
            + " AND NOT EXISTS (SELECT 1 FROM savings_accounts a WHERE a.customer_id = customers.id)"),
    WITHDRAWAL_REQUESTS("Withdrawal requests", "EXISTS (SELECT 1 FROM withdrawal_requests w"
            + " WHERE w.customer_id = customers.id AND w.status IN ('pending', 'approved'))"),
    WITH_ACTIVE_LOANS("With active loans", "(EXISTS (SELECT 1 FROM loans l WHERE l.customer_id = customers.id AND l.status = 'disbursed')"
            + " OR EXISTS (SELECT 1 FROM group_loans g WHERE g.customer_id = customers.id AND g.status = 'active'))"),
    UNASSIGNED("No agent", "customers.status = 'active' AND customers.assigned_agent_id IS NULL"),
    DORMANT("Dormant", "customers.status = 'dormant'"),
    CLOSED("Closed", "customers.status = 'closed'");

    private final String label;
    private final String condition;

    CustomerSegment(String label, String condition) {
        this.label = label;
        this.condition = condition;
    }

    public String label() {
        return label;
    }

    /** A trusted, constant SQL fragment — never built from user input. */
    public String condition() {
        return condition;
    }
}
