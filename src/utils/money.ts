/**
 * All API amounts are integer minor units (pesewas); display is Ghana cedis.
 * These helpers only format and parse — they never do financial math beyond
 * the cedi↔pesewa conversion of user input.
 */

const CEDI = 'GH₵';

function groupThousands(whole: string): string {
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/** 123456 → "GH₵ 1,234.56". Negative values render as "-GH₵ 12.00". */
export function formatMoney(minorUnits: number, options: { symbol?: boolean } = {}): string {
  const { symbol = true } = options;
  const negative = minorUnits < 0;
  const absolute = Math.abs(Math.round(minorUnits));
  const whole = groupThousands(String(Math.floor(absolute / 100)));
  const fraction = String(absolute % 100).padStart(2, '0');
  const body = `${whole}.${fraction}`;

  return `${negative ? '-' : ''}${symbol ? `${CEDI} ` : ''}${body}`;
}

/**
 * The API's own `*_formatted` strings use "GHS 1,234.50". Normalise them to
 * the same GH₵ presentation used everywhere else in the app.
 */
export function displayFormatted(formatted: string | null | undefined): string {
  if (!formatted) {
    return '—';
  }

  return formatted.replace(/^GHS\s?/, `${CEDI} `);
}

/**
 * Strip a typed amount down to a valid decimal string: digits, one dot, at
 * most two decimals, no leading zeros ("007.5" → "7.5"). Used as the
 * onChangeText filter for amount fields so invalid input never reaches state.
 */
export function sanitizeAmountInput(raw: string): string {
  // Commas are thousands separators in Ghana ("1,000"), never decimals —
  // converting them to '.' would turn GH₵ 1,000 into GH₵ 1.00.
  let cleaned = raw.replace(/[^\d.]/g, '');

  const firstDot = cleaned.indexOf('.');
  if (firstDot !== -1) {
    cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
    const [whole, fraction] = cleaned.split('.');
    cleaned = `${whole}.${fraction.slice(0, 2)}`;
  }

  cleaned = cleaned.replace(/^0+(?=\d)/, '');
  if (cleaned.startsWith('.')) {
    cleaned = `0${cleaned}`;
  }

  // 10 integer digits is far beyond any susu amount and keeps us in safe-int range.
  const [whole, fraction] = cleaned.split('.');
  if (whole.length > 10) {
    cleaned = whole.slice(0, 10) + (fraction !== undefined ? `.${fraction}` : '');
  }

  return cleaned;
}

/**
 * "12.5" → 1250 pesewas, without float drift ("0.29" * 100 = 28.999…).
 * Returns null for empty/invalid input; callers decide whether 0 is allowed.
 */
export function parseAmountToMinor(input: string): number | null {
  const cleaned = input.trim().replace(/,/g, '');
  if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) {
    return null;
  }

  const [whole, fraction = ''] = cleaned.split('.');

  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

/** Minor units → editable input string ("500" → "5.00"). */
export function minorToInput(minorUnits: number): string {
  return (minorUnits / 100).toFixed(2);
}
