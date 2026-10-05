package models;

import enums.DepositStatus;
import enums.GroupLoanStatus;
import enums.LoanFrequency;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;

/**
 * One loan issued to a single member of a loan group. The member enters a
 * total principal, a security deposit (paid into one of their savings
 * accounts), and the amount they pay each period; the schedule is spread
 * from that periodic amount at activation. No product, no interest, no
 * equal-split-across-members.
 */
public class GroupLoan {

    private String id;
    private String loanGroupId;
    private String loanGroupMemberId;
    private String customerId;
    private String agentId;
    private String activatedBy;
    private String receivableAccountId;

    private String loanNumber;

    private long principalAmount;
    private long securityDepositAmount;
    private long periodicAmount;
    private long outstandingBalance;
    private LoanFrequency repaymentFrequency;
    private LocalDate startDate;
    private int totalPeriods;

    private DepositStatus depositStatus;
    private GroupLoanStatus status;
    private String notes;
    private String clientReference;

    private Instant issuedAt;
    private Instant activatedAt;
    private Instant closedAt;
    private Instant writtenOffAt;
    private String writeOffReason;
    private Long writeOffAmount;
    private String writeOffSavingsAccountId;
    private Long writeOffSavingsApplied;
    private Instant cancelledAt;
    private String cancelledBy;
    private String cancellationReason;

    /** Populated by list/detail queries via a join; not always present. */
    private LoanGroup loanGroup;
    private Customer customer;
    private List<GroupLoanInstallment> installments;
    private List<GroupLoanRepayment> repayments;
    private List<GroupLoanDeposit> deposits;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getLoanGroupId() { return loanGroupId; }
    public void setLoanGroupId(String loanGroupId) { this.loanGroupId = loanGroupId; }

    public String getLoanGroupMemberId() { return loanGroupMemberId; }
    public void setLoanGroupMemberId(String loanGroupMemberId) { this.loanGroupMemberId = loanGroupMemberId; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public String getAgentId() { return agentId; }
    public void setAgentId(String agentId) { this.agentId = agentId; }

    public String getActivatedBy() { return activatedBy; }
    public void setActivatedBy(String activatedBy) { this.activatedBy = activatedBy; }

    public String getReceivableAccountId() { return receivableAccountId; }
    public void setReceivableAccountId(String receivableAccountId) { this.receivableAccountId = receivableAccountId; }


    public String getLoanNumber() { return loanNumber; }
    public void setLoanNumber(String loanNumber) { this.loanNumber = loanNumber; }

    public long getPrincipalAmount() { return principalAmount; }
    public void setPrincipalAmount(long principalAmount) { this.principalAmount = principalAmount; }

    public long getSecurityDepositAmount() { return securityDepositAmount; }
    public void setSecurityDepositAmount(long securityDepositAmount) { this.securityDepositAmount = securityDepositAmount; }

    public long getPeriodicAmount() { return periodicAmount; }
    public void setPeriodicAmount(long periodicAmount) { this.periodicAmount = periodicAmount; }

    public long getOutstandingBalance() { return outstandingBalance; }
    public void setOutstandingBalance(long outstandingBalance) { this.outstandingBalance = outstandingBalance; }

    public LoanFrequency getRepaymentFrequency() { return repaymentFrequency; }
    public void setRepaymentFrequency(LoanFrequency repaymentFrequency) { this.repaymentFrequency = repaymentFrequency; }

    public LocalDate getStartDate() { return startDate; }
    public void setStartDate(LocalDate startDate) { this.startDate = startDate; }

    public int getTotalPeriods() { return totalPeriods; }
    public void setTotalPeriods(int totalPeriods) { this.totalPeriods = totalPeriods; }

    public DepositStatus getDepositStatus() { return depositStatus; }
    public void setDepositStatus(DepositStatus depositStatus) { this.depositStatus = depositStatus; }

    public GroupLoanStatus getStatus() { return status; }
    public void setStatus(GroupLoanStatus status) { this.status = status; }

    public String getNotes() { return notes; }
    public void setNotes(String notes) { this.notes = notes; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }

    public Instant getIssuedAt() { return issuedAt; }
    public void setIssuedAt(Instant issuedAt) { this.issuedAt = issuedAt; }

    public Instant getActivatedAt() { return activatedAt; }
    public void setActivatedAt(Instant activatedAt) { this.activatedAt = activatedAt; }

    public Instant getClosedAt() { return closedAt; }
    public void setClosedAt(Instant closedAt) { this.closedAt = closedAt; }

    public Instant getWrittenOffAt() { return writtenOffAt; }
    public void setWrittenOffAt(Instant writtenOffAt) { this.writtenOffAt = writtenOffAt; }

    public Instant getCancelledAt() { return cancelledAt; }
    public void setCancelledAt(Instant cancelledAt) { this.cancelledAt = cancelledAt; }

    public String getCancelledBy() { return cancelledBy; }
    public void setCancelledBy(String cancelledBy) { this.cancelledBy = cancelledBy; }

    public String getCancellationReason() { return cancellationReason; }
    public void setCancellationReason(String cancellationReason) { this.cancellationReason = cancellationReason; }

    public String getWriteOffReason() { return writeOffReason; }
    public void setWriteOffReason(String writeOffReason) { this.writeOffReason = writeOffReason; }

    public Long getWriteOffAmount() { return writeOffAmount; }
    public void setWriteOffAmount(Long writeOffAmount) { this.writeOffAmount = writeOffAmount; }

    public String getWriteOffSavingsAccountId() { return writeOffSavingsAccountId; }
    public void setWriteOffSavingsAccountId(String writeOffSavingsAccountId) { this.writeOffSavingsAccountId = writeOffSavingsAccountId; }

    public Long getWriteOffSavingsApplied() { return writeOffSavingsApplied; }
    public void setWriteOffSavingsApplied(Long writeOffSavingsApplied) { this.writeOffSavingsApplied = writeOffSavingsApplied; }

    public LoanGroup getLoanGroup() { return loanGroup; }
    public void setLoanGroup(LoanGroup loanGroup) { this.loanGroup = loanGroup; }

    public Customer getCustomer() { return customer; }
    public void setCustomer(Customer customer) { this.customer = customer; }

    public List<GroupLoanInstallment> getInstallments() { return installments; }
    public void setInstallments(List<GroupLoanInstallment> installments) { this.installments = installments; }

    public List<GroupLoanRepayment> getRepayments() { return repayments; }
    public void setRepayments(List<GroupLoanRepayment> repayments) { this.repayments = repayments; }

    public List<GroupLoanDeposit> getDeposits() { return deposits; }
    public void setDeposits(List<GroupLoanDeposit> deposits) { this.deposits = deposits; }

    /** Principal repaid so far — the receivable started at the full principal. */
    public long amountRepaid() {
        return Math.max(0, principalAmount - outstandingBalance);
    }
}
