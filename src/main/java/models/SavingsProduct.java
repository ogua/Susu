package models;

import enums.CommissionType;

public class SavingsProduct {

    private String id;
    private String name;
    private String code;
    private String type;
    private long contributionAmount;
    private int cycleLengthDays;
    private CommissionType commissionType;
    private long commissionValue;
    /** Basis points of a withdrawn amount withheld before a target account's matures_at date. */
    private int earlyWithdrawalPenaltyBps;
    /** Annual rate (basis points) snapshotted onto a fixed-deposit account at open time. */
    private int interestRateBps;
    /** Target / fixed deposit only: default days from opening to maturity; null when unset. */
    private Integer termDays;
    /** Minor units per share; null for non-Shares products. */
    private Long parValue;
    private boolean active;

    public static final String TYPE_DAILY_SUSU = "daily_susu";
    public static final String TYPE_TARGET = "target";
    public static final String TYPE_FIXED_DEPOSIT = "fixed_deposit";
    public static final String TYPE_SHARES = "shares";

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getCode() { return code; }
    public void setCode(String code) { this.code = code; }

    public String getType() { return type; }
    public void setType(String type) { this.type = type; }

    public long getContributionAmount() { return contributionAmount; }
    public void setContributionAmount(long contributionAmount) { this.contributionAmount = contributionAmount; }

    public int getCycleLengthDays() { return cycleLengthDays; }
    public void setCycleLengthDays(int cycleLengthDays) { this.cycleLengthDays = cycleLengthDays; }

    public CommissionType getCommissionType() { return commissionType; }
    public void setCommissionType(CommissionType commissionType) { this.commissionType = commissionType; }

    public long getCommissionValue() { return commissionValue; }
    public void setCommissionValue(long commissionValue) { this.commissionValue = commissionValue; }

    public int getEarlyWithdrawalPenaltyBps() { return earlyWithdrawalPenaltyBps; }
    public void setEarlyWithdrawalPenaltyBps(int earlyWithdrawalPenaltyBps) { this.earlyWithdrawalPenaltyBps = earlyWithdrawalPenaltyBps; }

    public int getInterestRateBps() { return interestRateBps; }
    public void setInterestRateBps(int interestRateBps) { this.interestRateBps = interestRateBps; }

    public Integer getTermDays() { return termDays; }
    public void setTermDays(Integer termDays) { this.termDays = termDays; }
    public Long getParValue() { return parValue; }
    public void setParValue(Long parValue) { this.parValue = parValue; }

    public boolean isDailySusu() { return TYPE_DAILY_SUSU.equals(type); }
    public boolean isTarget() { return TYPE_TARGET.equals(type); }
    public boolean isFixedDeposit() { return TYPE_FIXED_DEPOSIT.equals(type); }
    public boolean isShares() { return TYPE_SHARES.equals(type); }

    /** Only susu-style products are collected in cycles and carry commission. An unset type means the column default, daily susu. */
    public boolean isCycleBased() { return type == null || isDailySusu() || isTarget(); }

    public boolean hasMaturity() { return isTarget() || isFixedDeposit(); }

    /**
     * Mirrors the backend model's saving hook: fixed deposits and shares never
     * carry commission, and only target/fixed deposit products keep a term.
     */
    public void normalize() {
        if (!isCycleBased()) {
            commissionType = CommissionType.NONE;
            commissionValue = 0;
        }
        if (!hasMaturity()) {
            termDays = null;
        }
    }

    public boolean isActive() { return active; }
    public void setActive(boolean active) { this.active = active; }
}
