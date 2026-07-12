package support;

/**
 * All amounts in SusuApp are integers in minor units (pesewas for GHS).
 * This is the single place that turns them into display strings and back —
 * the Java mirror of the backend's {@code App\Support\Money}.
 */
public final class Money {

    public static final String DEFAULT_CURRENCY = "GHS";

    private Money() {}

    public static String format(long minorUnits) {
        return format(minorUnits, DEFAULT_CURRENCY);
    }

    public static String format(long minorUnits, String currency) {
        return String.format("%s %,.2f", currency, minorUnits / 100.0);
    }

    /** Parses a user-entered major-unit amount ("125.50") into minor units. */
    public static long toMinorUnits(String majorUnits) {
        return Math.round(Double.parseDouble(majorUnits.trim()) * 100);
    }
}
