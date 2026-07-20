package models;

import java.time.Instant;

public class LoanGroupMember {

    private String id;
    private String loanGroupId;
    private String customerId;
    private String status;
    private Instant joinedAt;
    private Instant leftAt;

    /** Populated by detail queries; not always present. */
    private Customer customer;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getLoanGroupId() { return loanGroupId; }
    public void setLoanGroupId(String loanGroupId) { this.loanGroupId = loanGroupId; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public String getStatus() { return status; }
    public void setStatus(String status) { this.status = status; }

    public Instant getJoinedAt() { return joinedAt; }
    public void setJoinedAt(Instant joinedAt) { this.joinedAt = joinedAt; }

    public Instant getLeftAt() { return leftAt; }
    public void setLeftAt(Instant leftAt) { this.leftAt = leftAt; }

    public Customer getCustomer() { return customer; }
    public void setCustomer(Customer customer) { this.customer = customer; }

    @Override
    public String toString() {
        return customer != null ? customer.fullName() : customerId;
    }
}
