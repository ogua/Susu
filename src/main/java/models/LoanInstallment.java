package models;

import enums.InstallmentStatus;
import java.time.Instant;
import java.time.LocalDate;

public class LoanInstallment {

    private String id;
    private String loanId;
    private int sequence;
    private LocalDate dueDate;
    private long principalDue;
    private long interestDue;
    private long penaltyDue;
    private long principalPaid;
    private long interestPaid;
    private long penaltyPaid;
    private InstallmentStatus status;
    private Instant paidAt;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getLoanId() { return loanId; }
    public void setLoanId(String loanId) { this.loanId = loanId; }

    public int getSequence() { return sequence; }
    public void setSequence(int sequence) { this.sequence = sequence; }

    public LocalDate getDueDate() { return dueDate; }
    public void setDueDate(LocalDate dueDate) { this.dueDate = dueDate; }

    public long getPrincipalDue() { return principalDue; }
    public void setPrincipalDue(long principalDue) { this.principalDue = principalDue; }

    public long getInterestDue() { return interestDue; }
    public void setInterestDue(long interestDue) { this.interestDue = interestDue; }

    public long getPenaltyDue() { return penaltyDue; }
    public void setPenaltyDue(long penaltyDue) { this.penaltyDue = penaltyDue; }

    public long getPrincipalPaid() { return principalPaid; }
    public void setPrincipalPaid(long principalPaid) { this.principalPaid = principalPaid; }

    public long getInterestPaid() { return interestPaid; }
    public void setInterestPaid(long interestPaid) { this.interestPaid = interestPaid; }

    public long getPenaltyPaid() { return penaltyPaid; }
    public void setPenaltyPaid(long penaltyPaid) { this.penaltyPaid = penaltyPaid; }

    public InstallmentStatus getStatus() { return status; }
    public void setStatus(InstallmentStatus status) { this.status = status; }

    public Instant getPaidAt() { return paidAt; }
    public void setPaidAt(Instant paidAt) { this.paidAt = paidAt; }

    public long totalDue() {
        return principalDue + interestDue + penaltyDue;
    }

    public long amountPaid() {
        return principalPaid + interestPaid + penaltyPaid;
    }

    public long remaining() {
        return Math.max(0, totalDue() - amountPaid());
    }

    public long remainingPrincipal() {
        return Math.max(0, principalDue - principalPaid);
    }

    public long remainingInterest() {
        return Math.max(0, interestDue - interestPaid);
    }

    public long remainingPenalty() {
        return Math.max(0, penaltyDue - penaltyPaid);
    }
}
