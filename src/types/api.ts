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

export interface Customer {
  id: string;
  customer_code: string;
  first_name: string;
  last_name: string;
  phone: string;
  gender: string | null;
  photo_path: string | null;
  status: 'active' | 'dormant' | 'closed';
  branch_id: string;
  has_login: boolean;
  savings_accounts?: SavingsAccount[];
  created_at: string;
  updated_at: string;
}

export interface SavingsAccount {
  id: string;
  account_number: string;
  customer_id: string;
  customer?: Customer;
  product?: {
    id: string;
    name: string;
    type: string;
    cycle_length_days: number;
  };
  agent_id: string | null;
  contribution_amount: number;
  contribution_formatted: string;
  cycle_number: number;
  contributions_this_cycle: number;
  balance: number;
  balance_formatted: string;
  status: 'active' | 'dormant' | 'closed';
  opened_at: string;
  updated_at: string;
}

export interface Transaction {
  id: string;
  reference: string;
  type: 'collection' | 'commission' | 'withdrawal' | 'remittance' | 'reversal' | 'adjustment';
  status: 'pending' | 'completed' | 'failed' | 'reversed';
  payment_method: 'cash' | 'mobile_money' | 'internal';
  amount: number;
  amount_formatted: string;
  balance_after: number | null;
  description: string | null;
  recorded_at: string;
  posted_at: string;
}

export interface WithdrawalRequest {
  id: string;
  savings_account_id: string;
  amount: number;
  amount_formatted: string;
  reason: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'paid';
  rejected_reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface AgentDailySummary {
  id: string;
  summary_date: string;
  collections_total: number;
  collections_total_formatted: string;
  collections_count: number;
  expected_cash: number;
  declared_cash: number | null;
  variance: number | null;
  status: 'open' | 'submitted' | 'reconciled' | 'flagged';
  notes: string | null;
}

export type MobileMoneyProvider = 'mtn' | 'vod' | 'atl';

export type PaymentIntentStatus =
  | 'initiated'
  | 'pay_offline'
  | 'send_otp'
  | 'pending'
  | 'success'
  | 'failed'
  | 'abandoned';

export interface PaymentIntent {
  id: string;
  flow: 'charge_api' | 'checkout';
  channel: MobileMoneyProvider | null;
  phone: string | null;
  amount: number;
  amount_formatted: string;
  status: PaymentIntentStatus;
  journal_entry_id: string | null;
  authorization_url: string | null;
  created_at: string;
  updated_at: string;
}

export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}
