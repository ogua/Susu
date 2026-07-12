package models;

import enums.AccountStatus;

public class SavingsAccount {

    private String id;
    private String customerId;
    private String savingsProductId;
    private String agentId;
    private String ledgerAccountId;
    private String accountNumber;
    private long contributionAmount;
    private int cycleNumber;
    private String cycleStartedAt;
    private int contributionsThisCycle;
    private long balance;
    private AccountStatus status;
    private String openedAt;
    private String closedAt;

    /** Populated by list queries via a join; not always present. */
    private Customer customer;
    private SavingsProduct product;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public String getSavingsProductId() { return savingsProductId; }
    public void setSavingsProductId(String savingsProductId) { this.savingsProductId = savingsProductId; }

    public String getAgentId() { return agentId; }
    public void setAgentId(String agentId) { this.agentId = agentId; }

    public String getLedgerAccountId() { return ledgerAccountId; }
    public void setLedgerAccountId(String ledgerAccountId) { this.ledgerAccountId = ledgerAccountId; }

    public String getAccountNumber() { return accountNumber; }
    public void setAccountNumber(String accountNumber) { this.accountNumber = accountNumber; }

    public long getContributionAmount() { return contributionAmount; }
    public void setContributionAmount(long contributionAmount) { this.contributionAmount = contributionAmount; }

    public int getCycleNumber() { return cycleNumber; }
    public void setCycleNumber(int cycleNumber) { this.cycleNumber = cycleNumber; }

    public String getCycleStartedAt() { return cycleStartedAt; }
    public void setCycleStartedAt(String cycleStartedAt) { this.cycleStartedAt = cycleStartedAt; }

    public int getContributionsThisCycle() { return contributionsThisCycle; }
    public void setContributionsThisCycle(int contributionsThisCycle) { this.contributionsThisCycle = contributionsThisCycle; }

    public long getBalance() { return balance; }
    public void setBalance(long balance) { this.balance = balance; }

    public AccountStatus getStatus() { return status; }
    public void setStatus(AccountStatus status) { this.status = status; }

    public String getOpenedAt() { return openedAt; }
    public void setOpenedAt(String openedAt) { this.openedAt = openedAt; }

    public String getClosedAt() { return closedAt; }
    public void setClosedAt(String closedAt) { this.closedAt = closedAt; }

    public Customer getCustomer() { return customer; }
    public void setCustomer(Customer customer) { this.customer = customer; }

    public SavingsProduct getProduct() { return product; }
    public void setProduct(SavingsProduct product) { this.product = product; }
}
