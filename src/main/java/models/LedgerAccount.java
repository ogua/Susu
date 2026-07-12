package models;

import enums.LedgerAccountType;

public class LedgerAccount {

    private String id;
    private String code;
    private String name;
    private LedgerAccountType type;
    private String accountableType;
    private String accountableId;
    private long balance;
    private boolean system;

    public LedgerAccount() {}

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getCode() { return code; }
    public void setCode(String code) { this.code = code; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public LedgerAccountType getType() { return type; }
    public void setType(LedgerAccountType type) { this.type = type; }

    public String getAccountableType() { return accountableType; }
    public void setAccountableType(String accountableType) { this.accountableType = accountableType; }

    public String getAccountableId() { return accountableId; }
    public void setAccountableId(String accountableId) { this.accountableId = accountableId; }

    public long getBalance() { return balance; }
    public void setBalance(long balance) { this.balance = balance; }

    public boolean isSystem() { return system; }
    public void setSystem(boolean system) { this.system = system; }
}
