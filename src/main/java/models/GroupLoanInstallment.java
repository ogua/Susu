package models;

import enums.InstallmentStatus;
import java.time.Instant;
import java.time.LocalDate;

/** One row of a member's spread repayment schedule. Pure principal — no interest/penalty. */
public class GroupLoanInstallment {

    private String id;
    private String groupLoanId;
    private int sequence;
    private LocalDate dueDate;
    private long amountDue;
    private long amountPaid;
    private InstallmentStatus status;
    private Instant paidAt;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupLoanId() { return groupLoanId; }
    public void setGroupLoanId(String groupLoanId) { this.groupLoanId = groupLoanId; }

    public int getSequence() { return sequence; }
    public void setSequence(int sequence) { this.sequence = sequence; }

    public LocalDate getDueDate() { return dueDate; }
    public void setDueDate(LocalDate dueDate) { this.dueDate = dueDate; }

    public long getAmountDue() { return amountDue; }
    public void setAmountDue(long amountDue) { this.amountDue = amountDue; }

    public long getAmountPaid() { return amountPaid; }
    public void setAmountPaid(long amountPaid) { this.amountPaid = amountPaid; }

    public InstallmentStatus getStatus() { return status; }
    public void setStatus(InstallmentStatus status) { this.status = status; }

    public Instant getPaidAt() { return paidAt; }
    public void setPaidAt(Instant paidAt) { this.paidAt = paidAt; }

    public long remaining() {
        return Math.max(0, amountDue - amountPaid);
    }
}
