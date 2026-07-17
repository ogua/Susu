/** All API amounts are integer minor units (pesewas); display is GHS. */
export function formatMoney(minorUnits: number, currency = 'GHS'): string {
  return `${currency} ${(minorUnits / 100).toFixed(2)}`;
}
