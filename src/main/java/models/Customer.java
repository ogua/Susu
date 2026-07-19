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

    // eBanQR-parity KYC extensions (V007) — mirrors the Laravel backend's
    // 2026_07_18_130000_add_extended_kyc_fields_to_customers_table.php.
    private String clientType;
    private String externalId;
    private String placeOfBirth;
    private String nationality;
    private String email;
    private String cityTown;
    private String stateRegion;
    private String country;
    private String digitalAddress;
    private Double latitude;
    private Double longitude;
    private String maritalStatus;
    private String spouseName;
    private String spouseDateOfBirth;
    private String spouseOccupation;
    private Boolean hasPastLoan;
    private String pastLoanInstitution;
    private String spouseEmployerName;
    private String spouseEmployerAddress;
    private String spouseEmployerTown;
    private String spouseEmployerCounty;
    private String spouseEmployerRegion;
    private String religion;
    private String businessName;
    private String businessPhone;
    private String businessTin;
    private String businessLine;
    private String businessStructure;
    private String businessStartDate;
    private String businessIncomeLevel;
    private String businessAddress;
    private String businessTown;
    private String businessCounty;
    private String businessRegion;
    private Double businessLatitude;
    private Double businessLongitude;
    private String tin;
    private String otherNames;
    private String occupation;
    private String jobTitle;
    private String countryOfResidence;
    private String residencePermit;
    private String residencyStatus;
    private String assignedAgentId;

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

    public String getClientType() { return clientType; }
    public void setClientType(String clientType) { this.clientType = clientType; }

    public String getExternalId() { return externalId; }
    public void setExternalId(String externalId) { this.externalId = externalId; }

    public String getPlaceOfBirth() { return placeOfBirth; }
    public void setPlaceOfBirth(String placeOfBirth) { this.placeOfBirth = placeOfBirth; }

    public String getNationality() { return nationality; }
    public void setNationality(String nationality) { this.nationality = nationality; }

    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }

    public String getCityTown() { return cityTown; }
    public void setCityTown(String cityTown) { this.cityTown = cityTown; }

    public String getStateRegion() { return stateRegion; }
    public void setStateRegion(String stateRegion) { this.stateRegion = stateRegion; }

    public String getCountry() { return country; }
    public void setCountry(String country) { this.country = country; }

    public String getDigitalAddress() { return digitalAddress; }
    public void setDigitalAddress(String digitalAddress) { this.digitalAddress = digitalAddress; }

    public Double getLatitude() { return latitude; }
    public void setLatitude(Double latitude) { this.latitude = latitude; }

    public Double getLongitude() { return longitude; }
    public void setLongitude(Double longitude) { this.longitude = longitude; }

    public String getMaritalStatus() { return maritalStatus; }
    public void setMaritalStatus(String maritalStatus) { this.maritalStatus = maritalStatus; }

    public String getSpouseName() { return spouseName; }
    public void setSpouseName(String spouseName) { this.spouseName = spouseName; }

    public String getSpouseDateOfBirth() { return spouseDateOfBirth; }
    public void setSpouseDateOfBirth(String spouseDateOfBirth) { this.spouseDateOfBirth = spouseDateOfBirth; }

    public String getSpouseOccupation() { return spouseOccupation; }
    public void setSpouseOccupation(String spouseOccupation) { this.spouseOccupation = spouseOccupation; }

    public Boolean getHasPastLoan() { return hasPastLoan; }
    public void setHasPastLoan(Boolean hasPastLoan) { this.hasPastLoan = hasPastLoan; }

    public String getPastLoanInstitution() { return pastLoanInstitution; }
    public void setPastLoanInstitution(String pastLoanInstitution) { this.pastLoanInstitution = pastLoanInstitution; }

    public String getSpouseEmployerName() { return spouseEmployerName; }
    public void setSpouseEmployerName(String spouseEmployerName) { this.spouseEmployerName = spouseEmployerName; }

    public String getSpouseEmployerAddress() { return spouseEmployerAddress; }
    public void setSpouseEmployerAddress(String spouseEmployerAddress) { this.spouseEmployerAddress = spouseEmployerAddress; }

    public String getSpouseEmployerTown() { return spouseEmployerTown; }
    public void setSpouseEmployerTown(String spouseEmployerTown) { this.spouseEmployerTown = spouseEmployerTown; }

    public String getSpouseEmployerCounty() { return spouseEmployerCounty; }
    public void setSpouseEmployerCounty(String spouseEmployerCounty) { this.spouseEmployerCounty = spouseEmployerCounty; }

    public String getSpouseEmployerRegion() { return spouseEmployerRegion; }
    public void setSpouseEmployerRegion(String spouseEmployerRegion) { this.spouseEmployerRegion = spouseEmployerRegion; }

    public String getReligion() { return religion; }
    public void setReligion(String religion) { this.religion = religion; }

    public String getBusinessName() { return businessName; }
    public void setBusinessName(String businessName) { this.businessName = businessName; }

    public String getBusinessPhone() { return businessPhone; }
    public void setBusinessPhone(String businessPhone) { this.businessPhone = businessPhone; }

    public String getBusinessTin() { return businessTin; }
    public void setBusinessTin(String businessTin) { this.businessTin = businessTin; }

    public String getBusinessLine() { return businessLine; }
    public void setBusinessLine(String businessLine) { this.businessLine = businessLine; }

    public String getBusinessStructure() { return businessStructure; }
    public void setBusinessStructure(String businessStructure) { this.businessStructure = businessStructure; }

    public String getBusinessStartDate() { return businessStartDate; }
    public void setBusinessStartDate(String businessStartDate) { this.businessStartDate = businessStartDate; }

    public String getBusinessIncomeLevel() { return businessIncomeLevel; }
    public void setBusinessIncomeLevel(String businessIncomeLevel) { this.businessIncomeLevel = businessIncomeLevel; }

    public String getBusinessAddress() { return businessAddress; }
    public void setBusinessAddress(String businessAddress) { this.businessAddress = businessAddress; }

    public String getBusinessTown() { return businessTown; }
    public void setBusinessTown(String businessTown) { this.businessTown = businessTown; }

    public String getBusinessCounty() { return businessCounty; }
    public void setBusinessCounty(String businessCounty) { this.businessCounty = businessCounty; }

    public String getBusinessRegion() { return businessRegion; }
    public void setBusinessRegion(String businessRegion) { this.businessRegion = businessRegion; }

    public Double getBusinessLatitude() { return businessLatitude; }
    public void setBusinessLatitude(Double businessLatitude) { this.businessLatitude = businessLatitude; }

    public Double getBusinessLongitude() { return businessLongitude; }
    public void setBusinessLongitude(Double businessLongitude) { this.businessLongitude = businessLongitude; }

    public String getTin() { return tin; }
    public void setTin(String tin) { this.tin = tin; }

    public String getOtherNames() { return otherNames; }
    public void setOtherNames(String otherNames) { this.otherNames = otherNames; }

    public String getOccupation() { return occupation; }
    public void setOccupation(String occupation) { this.occupation = occupation; }

    public String getJobTitle() { return jobTitle; }
    public void setJobTitle(String jobTitle) { this.jobTitle = jobTitle; }

    public String getCountryOfResidence() { return countryOfResidence; }
    public void setCountryOfResidence(String countryOfResidence) { this.countryOfResidence = countryOfResidence; }

    public String getResidencePermit() { return residencePermit; }
    public void setResidencePermit(String residencePermit) { this.residencePermit = residencePermit; }

    public String getResidencyStatus() { return residencyStatus; }
    public void setResidencyStatus(String residencyStatus) { this.residencyStatus = residencyStatus; }

    public String getAssignedAgentId() { return assignedAgentId; }
    public void setAssignedAgentId(String assignedAgentId) { this.assignedAgentId = assignedAgentId; }

    public String fullName() {
        return (firstName + " " + lastName).trim();
    }
}
