import type { Role } from '@/types/api';

/**
 * Managers and company admins see their whole branch (every account and
 * customer); field agents only work their own. Mirrors the API's
 * AgentAssignment rule, so screens can word and gate things the same way.
 */
export function isManagerRole(role: Role | null | undefined): boolean {
  return role === 'branch_manager' || role === 'company_admin';
}

/** Closing a day (declaring cash in hand) is a field-agent duty, as on the web. */
export function canCloseDay(role: Role | null | undefined): boolean {
  return role === 'field_agent';
}
