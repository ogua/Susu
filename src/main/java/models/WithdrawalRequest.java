package models;

import enums.WithdrawalStatus;

public class WithdrawalRequest {

    private String id;
    private String savingsAccountId;
    private String customerId;
    private long amount;
    private long penaltyAmount;
    private String reason;
    private WithdrawalStatus status;
    private String requestedBy;
    private String approvedBy;
    private String rejectedReason;
    private String paidEntryId;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getSavingsAccountId() { return savingsAccountId; }
    public void setSavingsAccountId(String savingsAccountId) { this.savingsAccountId = savingsAccountId; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public long getAmount() { return amount; }
    public void setAmount(long amount) { this.amount = amount; }

    public long getPenaltyAmount() { return penaltyAmount; }
    public void setPenaltyAmount(long penaltyAmount) { this.penaltyAmount = penaltyAmount; }

    public String getReason() { return reason; }
    public void setReason(String reason) { this.reason = reason; }

    public WithdrawalStatus getStatus() { return status; }
    public void setStatus(WithdrawalStatus status) { this.status = status; }

    public String getRequestedBy() { return requestedBy; }
    public void setRequestedBy(String requestedBy) { this.requestedBy = requestedBy; }

    public String getApprovedBy() { return approvedBy; }
    public void setApprovedBy(String approvedBy) { this.approvedBy = approvedBy; }

    public String getRejectedReason() { return rejectedReason; }
    public void setRejectedReason(String rejectedReason) { this.rejectedReason = rejectedReason; }

    public String getPaidEntryId() { return paidEntryId; }
    public void setPaidEntryId(String paidEntryId) { this.paidEntryId = paidEntryId; }
}
