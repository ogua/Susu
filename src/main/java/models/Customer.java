package models;

import enums.AccountStatus;

public class Customer {

    private String id;
    private String customerCode;
    private String firstName;
    private String lastName;
    private String phone;
    private String gender;
    private String dateOfBirth;
    private String idType;
    private String idNumber;
    private String nextOfKinName;
    private String nextOfKinPhone;
    private String nextOfKinRelationship;
    private String address;
    private AccountStatus status;
    private String clientReference;
    private String registeredBy;

    public Customer() {}

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getCustomerCode() { return customerCode; }
    public void setCustomerCode(String customerCode) { this.customerCode = customerCode; }

    public String getFirstName() { return firstName; }
    public void setFirstName(String firstName) { this.firstName = firstName; }

    public String getLastName() { return lastName; }
    public void setLastName(String lastName) { this.lastName = lastName; }

    public String getPhone() { return phone; }
    public void setPhone(String phone) { this.phone = phone; }

    public String getGender() { return gender; }
    public void setGender(String gender) { this.gender = gender; }

    public String getDateOfBirth() { return dateOfBirth; }
    public void setDateOfBirth(String dateOfBirth) { this.dateOfBirth = dateOfBirth; }

    public String getIdType() { return idType; }
    public void setIdType(String idType) { this.idType = idType; }

    public String getIdNumber() { return idNumber; }
    public void setIdNumber(String idNumber) { this.idNumber = idNumber; }

    public String getNextOfKinName() { return nextOfKinName; }
    public void setNextOfKinName(String nextOfKinName) { this.nextOfKinName = nextOfKinName; }

    public String getNextOfKinPhone() { return nextOfKinPhone; }
    public void setNextOfKinPhone(String nextOfKinPhone) { this.nextOfKinPhone = nextOfKinPhone; }

    public String getNextOfKinRelationship() { return nextOfKinRelationship; }
    public void setNextOfKinRelationship(String nextOfKinRelationship) { this.nextOfKinRelationship = nextOfKinRelationship; }

    public String getAddress() { return address; }
    public void setAddress(String address) { this.address = address; }

    public AccountStatus getStatus() { return status; }
    public void setStatus(AccountStatus status) { this.status = status; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }

    public String getRegisteredBy() { return registeredBy; }
    public void setRegisteredBy(String registeredBy) { this.registeredBy = registeredBy; }

    public String fullName() {
        return (firstName + " " + lastName).trim();
    }
}
