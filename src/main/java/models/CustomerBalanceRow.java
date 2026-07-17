package models;

/** One open savings account's balance with customer context — Customer Balances read model. */
public class CustomerBalanceRow {

    private final String accountNumber;
    private final String customerName;
    private final String customerPhone;
    private final String productName;
    private final String agentName;
    private final String status;
    private final long balance;

    public CustomerBalanceRow(String accountNumber, String customerName, String customerPhone,
                              String productName, String agentName, String status, long balance) {
        this.accountNumber = accountNumber;
        this.customerName = customerName;
        this.customerPhone = customerPhone;
        this.productName = productName;
        this.agentName = agentName;
        this.status = status;
        this.balance = balance;
    }

    public String getAccountNumber() { return accountNumber; }
    public String getCustomerName() { return customerName; }
    public String getCustomerPhone() { return customerPhone; }
    public String getProductName() { return productName; }
    public String getAgentName() { return agentName; }
    public String getStatus() { return status; }
    public long getBalance() { return balance; }
}
