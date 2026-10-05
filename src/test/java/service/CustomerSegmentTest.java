package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.CustomerSegment;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.UUID;
import models.Customer;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;

/** Customer list segments and totals — mirrors the backend's segment counts. */
class CustomerSegmentTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-customer-segment-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private Customer newCustomer(String first) throws Exception {
        Customer customer = new Customer();
        customer.setFirstName(first);
        customer.setLastName("Segment");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "agent-1", null);
    }

    @Test
    void countsAndFiltersBySegment() throws Exception {
        Customer saver = newCustomer("Akosua");
        newCustomer("Kwesi"); // registered, no account yet → pending
        accounts.open(saver.getId(), products.getOrCreateDefault().getId(), "agent-1", null);

        CustomerService.Overview overview = customers.overview();
        assertEquals(2, overview.segments().get(CustomerSegment.ALL));
        assertEquals(1, overview.segments().get(CustomerSegment.PENDING));
        assertEquals(2, overview.newThisMonth());

        List<Customer> pending = customers.search(null, CustomerSegment.PENDING);
        assertEquals(1, pending.size());
        assertEquals("Kwesi", pending.get(0).getFirstName());
        assertEquals(1, customers.search("Akosua", CustomerSegment.ALL).size());
        assertEquals(0, customers.search("Akosua", CustomerSegment.PENDING).size());
    }
}
