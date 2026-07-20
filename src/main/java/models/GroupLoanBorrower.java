package models;

public class GroupLoanBorrower {

    private String id;
    private String groupLoanId;
    private String loanGroupMemberId;
    private String customerId;
    private long sharePrincipal;
    private long shareOutstanding;

    /** Populated by detail queries; not always present. */
    private Customer customer;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupLoanId() { return groupLoanId; }
    public void setGroupLoanId(String groupLoanId) { this.groupLoanId = groupLoanId; }

    public String getLoanGroupMemberId() { return loanGroupMemberId; }
    public void setLoanGroupMemberId(String loanGroupMemberId) { this.loanGroupMemberId = loanGroupMemberId; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public long getSharePrincipal() { return sharePrincipal; }
    public void setSharePrincipal(long sharePrincipal) { this.sharePrincipal = sharePrincipal; }

    public long getShareOutstanding() { return shareOutstanding; }
    public void setShareOutstanding(long shareOutstanding) { this.shareOutstanding = shareOutstanding; }

    public Customer getCustomer() { return customer; }
    public void setCustomer(Customer customer) { this.customer = customer; }

    @Override
    public String toString() {
        return customer != null ? customer.fullName() : customerId;
    }
}
