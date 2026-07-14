package models;

import enums.InterestMethod;
import enums.LoanFrequency;

public class LoanProduct {

    private String id;
    private String name;
    private String code;
    private InterestMethod interestMethod;
    private int interestRateBps;
    private int termPeriodCount;
    private LoanFrequency repaymentFrequency;
    private long originationFeeAmount;
    private int penaltyRateBps;
    private int gracePeriodDays;
    private long minAmount;
    private long maxAmount;
    private boolean active;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getCode() { return code; }
    public void setCode(String code) { this.code = code; }

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

    public long getMinAmount() { return minAmount; }
    public void setMinAmount(long minAmount) { this.minAmount = minAmount; }

    public long getMaxAmount() { return maxAmount; }
    public void setMaxAmount(long maxAmount) { this.maxAmount = maxAmount; }

    public boolean isActive() { return active; }
    public void setActive(boolean active) { this.active = active; }
}
