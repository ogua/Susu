package service;

import java.util.List;

public record LoanEligibilityResult(boolean eligible, List<String> reasons) {
}
