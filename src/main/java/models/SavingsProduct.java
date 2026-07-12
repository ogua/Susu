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
    private boolean active;

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

    public boolean isActive() { return active; }
    public void setActive(boolean active) { this.active = active; }
}
