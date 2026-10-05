package service;

import enums.InterestMethod;
import enums.LoanFrequency;
import java.time.LocalDate;
import java.util.List;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Everything the loan application can add on top of the product's defaults —
 * a parity port of the backend's {@code App\Actions\Loans\LoanApplicationDetails}.
 * Every override is nullable: null means "use the product". {@code charges}
 * null means "the product's origination fee as one Processing fee charge".
 * Amounts are pesewas.
 */
public record LoanApplicationDetails(
        Integer termPeriodCount,
        LoanFrequency repaymentFrequency,
        Integer interestRateBps,
        InterestMethod interestMethod,
        Integer gracePeriodDays,
        LocalDate firstRepaymentDate,
        String purpose,
        List<Charge> charges,
        List<Collateral> collaterals,
        List<Guarantor> guarantors) {

    public record Charge(String name, long amount) {
    }

    public record Collateral(String type, String description, long estimatedValue, String serialNumber, String notes) {
    }

    public record Guarantor(String customerId, String name, String phone, String relationship, String address,
                            Long guaranteedAmount) {
    }

    public LoanApplicationDetails {
        collaterals = collaterals == null ? List.of() : List.copyOf(collaterals);
        guarantors = guarantors == null ? List.of() : List.copyOf(guarantors);
        charges = charges == null ? null : List.copyOf(charges);
    }

    public static LoanApplicationDetails none() {
        return new LoanApplicationDetails(null, null, null, null, null, null, null, null, List.of(), List.of());
    }

    /** Adds the optional fields to a loan.apply outbox payload, matching StoreLoanApplicationRequest::detailRules(). */
    void writeTo(JSONObject payload) {
        if (termPeriodCount != null) {
            payload.put("term_period_count", termPeriodCount);
        }
        if (repaymentFrequency != null) {
            payload.put("repayment_frequency", repaymentFrequency.value());
        }
        if (interestRateBps != null) {
            payload.put("interest_rate_bps", interestRateBps);
        }
        if (interestMethod != null) {
            payload.put("interest_method", interestMethod.value());
        }
        if (gracePeriodDays != null) {
            payload.put("grace_period_days", gracePeriodDays);
        }
        if (firstRepaymentDate != null) {
            payload.put("first_repayment_date", firstRepaymentDate.toString());
        }
        if (purpose != null) {
            payload.put("purpose", purpose);
        }
        if (charges != null) {
            JSONArray array = new JSONArray();
            charges.forEach(charge -> array.put(new JSONObject().put("name", charge.name()).put("amount", charge.amount())));
            payload.put("charges", array);
        }
        if (!collaterals.isEmpty()) {
            JSONArray array = new JSONArray();
            collaterals.forEach(collateral -> array.put(new JSONObject()
                    .put("type", collateral.type())
                    .put("description", collateral.description())
                    .put("estimated_value", collateral.estimatedValue())
                    .putOpt("serial_number", collateral.serialNumber())
                    .putOpt("notes", collateral.notes())));
            payload.put("collaterals", array);
        }
        if (!guarantors.isEmpty()) {
            JSONArray array = new JSONArray();
            guarantors.forEach(guarantor -> array.put(new JSONObject()
                    .put("name", guarantor.name())
                    .putOpt("customer_id", guarantor.customerId())
                    .putOpt("phone", guarantor.phone())
                    .putOpt("relationship", guarantor.relationship())
                    .putOpt("address", guarantor.address())
                    .putOpt("guaranteed_amount", guarantor.guaranteedAmount())));
            payload.put("guarantors", array);
        }
    }
}
