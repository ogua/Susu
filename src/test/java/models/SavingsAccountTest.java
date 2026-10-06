package models;

import enums.AccountStatus;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Mirrors the backend's SavingsAccount::acceptsCollections rules. */
class SavingsAccountTest {

    private static SavingsProduct product(String type) {
        SavingsProduct product = new SavingsProduct();
        product.setType(type);
        return product;
    }

    private static SavingsAccount account(long balance, String maturedAt, AccountStatus status) {
        SavingsAccount account = new SavingsAccount();
        account.setBalance(balance);
        account.setMaturedAt(maturedAt);
        account.setStatus(status);
        return account;
    }

    @Test
    void acceptsCollectionsOnlyWhereTheProductTypeAllowsThem() {
        SavingsProduct fixedDeposit = product(SavingsProduct.TYPE_FIXED_DEPOSIT);

        assertTrue(account(500, null, AccountStatus.ACTIVE).acceptsCollections(product(SavingsProduct.TYPE_DAILY_SUSU)));
        assertFalse(account(0, null, AccountStatus.ACTIVE).acceptsCollections(product(SavingsProduct.TYPE_SHARES)));
        assertTrue(account(0, null, AccountStatus.ACTIVE).acceptsCollections(fixedDeposit));
        assertFalse(account(1_000_00, null, AccountStatus.ACTIVE).acceptsCollections(fixedDeposit));
        assertFalse(account(0, "2026-10-01T00:00:00Z", AccountStatus.ACTIVE).acceptsCollections(fixedDeposit));
        assertFalse(account(0, null, AccountStatus.CLOSED).acceptsCollections(product(SavingsProduct.TYPE_DAILY_SUSU)));
    }
}
