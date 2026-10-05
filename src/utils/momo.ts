import type { Option } from '@/components/ui';
import type { MobileMoneyProvider } from '@/types/api';

export const MOMO_PROVIDERS: Option<MobileMoneyProvider>[] = [
  { value: 'mtn', label: 'MTN' },
  { value: 'vod', label: 'Telecel' },
  { value: 'atl', label: 'AirtelTigo' },
];

/**
 * UX-only check mirroring the server: a Ghana mobile number is 10 digits
 * starting with 0, or 233 + 9 digits. The server remains authoritative.
 */
export function momoPhoneError(phone: string): string | null {
  const digits = phone.replace(/[\s-]/g, '');
  if (!digits) {
    return 'Enter the mobile money number.';
  }
  if (/^0\d{9}$/.test(digits) || /^\+?233\d{9}$/.test(digits)) {
    return null;
  }

  return 'Enter a 10-digit number, e.g. 024 123 4567.';
}

export function normalizeMomoPhone(phone: string): string {
  return phone.replace(/[\s-]/g, '');
}
