package models;

public class CustomerBeneficiary {

    private String id;
    private String customerId;
    private String name;
    private String relationship;
    private long amountOfLegacy;
    private String phone;
    private String address;
    private String town;
    private String county;
    private String stateRegion;

    public CustomerBeneficiary() {}

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getCustomerId() { return customerId; }
    public void setCustomerId(String customerId) { this.customerId = customerId; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getRelationship() { return relationship; }
    public void setRelationship(String relationship) { this.relationship = relationship; }

    public long getAmountOfLegacy() { return amountOfLegacy; }
    public void setAmountOfLegacy(long amountOfLegacy) { this.amountOfLegacy = amountOfLegacy; }

    public String getPhone() { return phone; }
    public void setPhone(String phone) { this.phone = phone; }

    public String getAddress() { return address; }
    public void setAddress(String address) { this.address = address; }

    public String getTown() { return town; }
    public void setTown(String town) { this.town = town; }

    public String getCounty() { return county; }
    public void setCounty(String county) { this.county = county; }

    public String getStateRegion() { return stateRegion; }
    public void setStateRegion(String stateRegion) { this.stateRegion = stateRegion; }
}
