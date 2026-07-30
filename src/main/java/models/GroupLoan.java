package models;

import enums.GroupLoanStatus;
import enums.InterestMethod;
import enums.LoanFrequency;
import java.time.Instant;
import java.util.List;

public class GroupLoan {

    private String id;
    private String loanGroupId;
    private String loanProductId;
    private String agentId;
    private String approvedBy;
    private String receivableAccountId;

    private String loanNumber;

    private long principalAmount;
    private InterestMethod interestMethod;
    private int interestRateBps;
    private int termPeriodCount;
    private LoanFrequency repaymentFrequency;
    private long originationFeeAmount;
    private int penaltyRateBps;
    private int gracePeriodDays;

    private long totalInterest;
    private long totalRepayable;
    private long outstandingBalance;
    private Integer memberCountAtDisbursement;

    private GroupLoanStatus status;
    private String rejectionReason;
    private String notes;
    private String clientReference;

    private Instant appliedAt;
    private Instant approvedAt;
    private Instant disbursedAt;
    private Instant closedAt;

    private String previousGroupLoanId;
    private long rolledOverAmount;
    private Instant refinancedAt;
    private String refinanceType;
    private String refinanceReason;
    private Long refinanceAmount;

    /** Populated by list/detail queries via a join; not always present. */
    private LoanGroup loanGroup;
    private LoanProduct product;
    private List<GroupLoanBorrower> borrowers;
    private List<GroupLoanInstallment> installments;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getLoanGroupId() { return loanGroupId; }
    public void setLoanGroupId(String loanGroupId) { this.loanGroupId = loanGroupId; }

    public String getLoanProductId() { return loanProductId; }
    public void setLoanProductId(String loanProductId) { this.loanProductId = loanProductId; }

    public String getAgentId() { return agentId; }
    public void setAgentId(String agentId) { this.agentId = agentId; }

    public String getApprovedBy() { return approvedBy; }
    public void setApprovedBy(String approvedBy) { this.approvedBy = approvedBy; }

    public String getReceivableAccountId() { return receivableAccountId; }
    public void setReceivableAccountId(String receivableAccountId) { this.receivableAccountId = receivableAccountId; }

    public String getLoanNumber() { return loanNumber; }
    public void setLoanNumber(String loanNumber) { this.loanNumber = loanNumber; }

    public long getPrincipalAmount() { return principalAmount; }
    public void setPrincipalAmount(long principalAmount) { this.principalAmount = principalAmount; }

    public InterestMethod getInterestMethod() { return interestMethod; }
    public void setInterestMethod(InterestMethod interestMethod) { this.interestMethod = interestMethod; }

    public int getInterestRateBps() { return interestRateBps; }
    public void setInterestRateBps(int interestRateBps) { this.interestRateBps = interestRateBps; }

    public int getTermPeriodCount() { return termPeriodCount; }
    public void setTermPeriodCount(int termPeriodCount) { this.termPeriodCount = termPeriodCount; }

    public LoanFrequency getRepaymentFrequency() { return repaymentFrequency; }
    public void setRepaymentFrequency(LoanFrequency repaymentFrequency) { this.repaymentFrequency = repaymentFrequency; }

    public long getOriginationFeeAmount() { return originationFeeAmount; }
    public void setOriginationFeeAmount(long originationFeeAmount) { this.originationFeeAmount = originationFeeAmount; }

    public int getPenaltyRateBps() { return penaltyRateBps; }
    public void setPenaltyRateBps(int penaltyRateBps) { this.penaltyRateBps = penaltyRateBps; }

    public int getGracePeriodDays() { return gracePeriodDays; }
    public void setGracePeriodDays(int gracePeriodDays) { this.gracePeriodDays = gracePeriodDays; }

    public long getTotalInterest() { return totalInterest; }
    public void setTotalInterest(long totalInterest) { this.totalInterest = totalInterest; }

    public long getTotalRepayable() { return totalRepayable; }
    public void setTotalRepayable(long totalRepayable) { this.totalRepayable = totalRepayable; }

    public long getOutstandingBalance() { return outstandingBalance; }
    public void setOutstandingBalance(long outstandingBalance) { this.outstandingBalance = outstandingBalance; }

    public Integer getMemberCountAtDisbursement() { return memberCountAtDisbursement; }
    public void setMemberCountAtDisbursement(Integer memberCountAtDisbursement) { this.memberCountAtDisbursement = memberCountAtDisbursement; }

    public GroupLoanStatus getStatus() { return status; }
    public void setStatus(GroupLoanStatus status) { this.status = status; }

    public String getRejectionReason() { return rejectionReason; }
    public void setRejectionReason(String rejectionReason) { this.rejectionReason = rejectionReason; }

    public String getNotes() { return notes; }
    public void setNotes(String notes) { this.notes = notes; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }

    public Instant getAppliedAt() { return appliedAt; }
    public void setAppliedAt(Instant appliedAt) { this.appliedAt = appliedAt; }

    public Instant getApprovedAt() { return approvedAt; }
    public void setApprovedAt(Instant approvedAt) { this.approvedAt = approvedAt; }

    public Instant getDisbursedAt() { return disbursedAt; }
    public void setDisbursedAt(Instant disbursedAt) { this.disbursedAt = disbursedAt; }

    public Instant getClosedAt() { return closedAt; }
    public void setClosedAt(Instant closedAt) { this.closedAt = closedAt; }

    public String getPreviousGroupLoanId() { return previousGroupLoanId; }
    public void setPreviousGroupLoanId(String previousGroupLoanId) { this.previousGroupLoanId = previousGroupLoanId; }

    public long getRolledOverAmount() { return rolledOverAmount; }
    public void setRolledOverAmount(long rolledOverAmount) { this.rolledOverAmount = rolledOverAmount; }

    public Instant getRefinancedAt() { return refinancedAt; }
    public void setRefinancedAt(Instant refinancedAt) { this.refinancedAt = refinancedAt; }

    public String getRefinanceType() { return refinanceType; }
    public void setRefinanceType(String refinanceType) { this.refinanceType = refinanceType; }

    public String getRefinanceReason() { return refinanceReason; }
    public void setRefinanceReason(String refinanceReason) { this.refinanceReason = refinanceReason; }

    public Long getRefinanceAmount() { return refinanceAmount; }
    public void setRefinanceAmount(Long refinanceAmount) { this.refinanceAmount = refinanceAmount; }

    public LoanGroup getLoanGroup() { return loanGroup; }
    public void setLoanGroup(LoanGroup loanGroup) { this.loanGroup = loanGroup; }

    public LoanProduct getProduct() { return product; }
    public void setProduct(LoanProduct product) { this.product = product; }

    public List<GroupLoanBorrower> getBorrowers() { return borrowers; }
    public void setBorrowers(List<GroupLoanBorrower> borrowers) { this.borrowers = borrowers; }

    public List<GroupLoanInstallment> getInstallments() { return installments; }
    public void setInstallments(List<GroupLoanInstallment> installments) { this.installments = installments; }
}
