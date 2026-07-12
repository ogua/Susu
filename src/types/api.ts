/**
 * Types mirroring the Laravel API's Eloquent Resource responses (/api/v1).
 * Keep in sync with app/Http/Resources/V1 in the SusuApp backend.
 */

export type Role =
  | 'super_admin'
  | 'company_admin'
  | 'branch_manager'
  | 'field_agent'
  | 'customer';

export interface CompanySummary {
  id: string;
  name: string;
  slug: string;
  logo: string | null;
  primary_color: string;
  secondary_color: string;
}

export interface BranchSummary {
  id: string;
  name: string;
  slug: string;
  code: string | null;
}

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  photo_path: string | null;
  is_active: boolean;
  role: Role | null;
  company?: CompanySummary;
  branches?: BranchSummary[];
}

export interface LoginResponse {
  token: string;
  user: User;
}

export interface ApiValidationError {
  message: string;
  errors: Record<string, string[]>;
}
