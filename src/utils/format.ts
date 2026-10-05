/** Date/label formatting shared across screens (en-GH, day-first). */

const LOCALE = 'en-GB';

export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return date.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatDateTime(value: string | Date | null | undefined): string {
  if (!value) {
    return '—';
  }
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return `${date.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' })}, ${date.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' })}`;
}

/** "2 min ago", "3 h ago", or the date for older stamps. */
export function formatRelative(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }
  const diffMs = Date.now() - new Date(value).getTime();
  const minutes = Math.round(diffMs / 60_000);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} h ago`;

  return formatDate(value);
}

/** "written_off" → "Written off". */
export function humanize(value: string | null | undefined): string {
  if (!value) {
    return '';
  }
  const spaced = value.replaceAll('_', ' ');

  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export function roleLabel(role: string | null | undefined): string {
  switch (role) {
    case 'field_agent':
      return 'Field Agent';
    case 'branch_manager':
      return 'Branch Manager';
    case 'company_admin':
      return 'Administrator';
    case 'super_admin':
      return 'Platform Admin';
    case 'customer':
      return 'Customer';
    default:
      return humanize(role);
  }
}

export function initials(name: string | null | undefined): string {
  if (!name) {
    return '?';
  }
  const parts = name.trim().split(/\s+/);

  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '')).toUpperCase();
}

/** First word of a name, for greetings. */
export function firstName(name: string | null | undefined): string {
  return name?.trim().split(/\s+/)[0] ?? '';
}

export function greeting(now = new Date()): string {
  const hour = now.getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';

  return 'Good evening';
}

/** Auto-insert dashes while typing a date: "20260105" → "2026-01-05". */
export function maskDateInput(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 8);
  if (digits.length <= 4) return digits;
  if (digits.length <= 6) return `${digits.slice(0, 4)}-${digits.slice(4)}`;

  return `${digits.slice(0, 4)}-${digits.slice(4, 6)}-${digits.slice(6)}`;
}

/** Strict YYYY-MM-DD that is a real calendar date (rejects 2026-02-31). */
export function isValidIsoDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}
