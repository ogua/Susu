package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.CustomerBeneficiary;
import models.CustomerFamilyMember;
import models.CustomerIdentification;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the eBanQR-parity KYC extension: extended flat fields, business
 * profile fields, and the identification/beneficiary/family-member child
 * rows including primary-identification mirroring onto the legacy
 * id_type/id_number columns. Mirrors tests/Feature/Filament/CustomerResourceTest.php.
 */
class CustomerServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-customer-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private String uniquePhone() {
        return "+233" + UUID.randomUUID().toString().substring(0, 8);
    }

    @Test
    void registersAnIndividualCustomerAndPersistsExtendedFlatFields() throws Exception {
        Customer customer = new Customer();
        customer.setClientType("individual");
        customer.setFirstName("Ama");
        customer.setLastName("Owusu");
        customer.setPhone(uniquePhone());
        customer.setGender("female");
        customer.setPlaceOfBirth("Accra");
        customer.setNationality("Ghanaian");
        customer.setMaritalStatus("married");
        customer.setSpouseName("Kofi Owusu");
        customer.setHasPastLoan(true);
        customer.setLatitude(5.6037);
        customer.setLongitude(-0.1870);

        Customer created = customers.register(customer, "agent-1", null);

        assertEquals("individual", created.getClientType());
        assertEquals("Accra", created.getPlaceOfBirth());
        assertEquals("Ghanaian", created.getNationality());
        assertEquals("married", created.getMaritalStatus());
        assertEquals("Kofi Owusu", created.getSpouseName());
        assertTrue(created.getHasPastLoan());
        assertEquals(5.6037, created.getLatitude());
        assertEquals(-0.1870, created.getLongitude());
    }

    @Test
    void registersABusinessCustomerAndPersistsBusinessProfileFields() throws Exception {
        Customer customer = new Customer();
        customer.setClientType("business");
        customer.setFirstName("Kwame");
        customer.setLastName("Mensah");
        customer.setPhone(uniquePhone());
        customer.setBusinessName("Mensah Traders");
        customer.setBusinessStructure("sole_proprietorship");
        customer.setBusinessLine("trading_retail");
        customer.setBusinessStartDate("2020-01-15");
        customer.setBusinessIncomeLevel("medium");

        Customer created = customers.register(customer, "agent-1", null);

        assertEquals("business", created.getClientType());
        assertEquals("Mensah Traders", created.getBusinessName());
        assertEquals("sole_proprietorship", created.getBusinessStructure());
        assertEquals("trading_retail", created.getBusinessLine());
        assertEquals("2020-01-15", created.getBusinessStartDate());
        assertEquals("medium", created.getBusinessIncomeLevel());
    }

    @Test
    void persistsChildRowsAndMirrorsThePrimaryIdentificationOntoTheLegacyColumns() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Efua");
        customer.setLastName("Boateng");
        customer.setPhone(uniquePhone());

        CustomerIdentification passport = new CustomerIdentification();
        passport.setIdType("passport");
        passport.setIdNumber("P1234567");
        passport.setIssueDate("2019-06-01");
        passport.setIsPrimary(false);

        CustomerIdentification ghanaCard = new CustomerIdentification();
        ghanaCard.setIdType("ghana_card");
        ghanaCard.setIdNumber("GHA-000111222-3");
        ghanaCard.setIssueDate("2021-03-10");
        ghanaCard.setIsPrimary(true);

        CustomerBeneficiary beneficiary = new CustomerBeneficiary();
        beneficiary.setName("Yaw Boateng");
        beneficiary.setRelationship("Son");
        beneficiary.setAmountOfLegacy(500000);

        CustomerFamilyMember familyMember = new CustomerFamilyMember();
        familyMember.setName("Abena Boateng");
        familyMember.setRelationship("Daughter");

        Customer created = customers.register(customer, "agent-1", null,
                List.of(passport, ghanaCard), List.of(beneficiary), List.of(familyMember));

        assertEquals("ghana_card", created.getIdType());
        assertEquals("GHA-000111222-3", created.getIdNumber());

        List<CustomerIdentification> identifications = customers.findIdentifications(created.getId());
        assertEquals(2, identifications.size());

        List<CustomerBeneficiary> beneficiaries = customers.findBeneficiaries(created.getId());
        assertEquals(1, beneficiaries.size());
        assertEquals("Yaw Boateng", beneficiaries.get(0).getName());
        assertEquals(500000, beneficiaries.get(0).getAmountOfLegacy());

        List<CustomerFamilyMember> familyMembers = customers.findFamilyMembers(created.getId());
        assertEquals(1, familyMembers.size());
        assertEquals("Abena Boateng", familyMembers.get(0).getName());
    }

    @Test
    void mirrorsTheFirstIdentificationWhenNoneIsFlaggedPrimary() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Kojo");
        customer.setLastName("Asare");
        customer.setPhone(uniquePhone());

        CustomerIdentification votersId = new CustomerIdentification();
        votersId.setIdType("voters_id");
        votersId.setIdNumber("V998877");
        votersId.setIssueDate("2018-02-01");

        Customer created = customers.register(customer, "agent-1", null, List.of(votersId), List.of(), List.of());

        assertEquals("voters_id", created.getIdType());
        assertEquals("V998877", created.getIdNumber());
    }

    @Test
    void leavesLegacyIdColumnsUntouchedWhenNoIdentificationsAreProvided() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Nana");
        customer.setLastName("Yeboah");
        customer.setPhone(uniquePhone());

        Customer created = customers.register(customer, "agent-1", null);

        assertEquals("ghana_card", created.getIdType());
        assertNull(created.getIdNumber());
    }

    @Test
    void onlyEnqueuesTheOutboxPayloadInHybridMode() throws Exception {
        AppConfig.set("sync.enabled", "false");
        OutboxService outbox = new OutboxService();
        int before = outbox.pendingCount();

        Customer customer = new Customer();
        customer.setFirstName("Standalone");
        customer.setLastName("Customer");
        customer.setPhone(uniquePhone());
        customers.register(customer, "agent-1", null);

        assertEquals(before, outbox.pendingCount());

        AppConfig.set("sync.enabled", "true");
        Customer hybridCustomer = new Customer();
        hybridCustomer.setFirstName("Hybrid");
        hybridCustomer.setLastName("Customer");
        hybridCustomer.setPhone(uniquePhone());
        customers.register(hybridCustomer, "agent-1", null);

        assertEquals(before + 1, outbox.pendingCount());

        List<OutboxService.OutboxItem> pending = outbox.pending(10);
        OutboxService.OutboxItem item = pending.get(pending.size() - 1);
        assertEquals("customer.register", item.opType());
        assertTrue(item.payload().contains("Hybrid"));

        AppConfig.set("sync.enabled", "false");
    }
}
