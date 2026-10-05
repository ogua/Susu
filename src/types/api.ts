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
  /** Absent on users cached before the API added it. */
  photo_url?: string | null;
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

export type ClientType = 'individual' | 'business';

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
  client_type: ClientType;
  business_name: string | null;
  savings_accounts?: SavingsAccount[];
  created_at: string;
  updated_at: string;
}

export type SavingsProductType = 'daily_susu' | 'target' | 'fixed_deposit' | 'shares';

export interface SavingsProduct {
  id: string;
  name: string;
  code: string;
  type: SavingsProductType;
  contribution_amount: number;
  cycle_length_days: number;
  commission_type: string;
  commission_value: number;
  interest_rate_bps: number;
  par_value: number | null;
  is_active: boolean;
}

export interface SavingsAccount {
  id: string;
  account_number: string;
  customer_id: string;
  customer?: Customer;
  product?: {
    id: string;
    name: string;
    type: SavingsProductType;
    cycle_length_days: number;
    interest_rate_bps: number;
    par_value: number | null;
  };
  agent_id: string | null;
  contribution_amount: number;
  contribution_formatted: string;
  cycle_number: number;
  contributions_this_cycle: number;
  balance: number;
  balance_formatted: string;
  target_amount: number | null;
  target_amount_formatted: string | null;
  matures_at: string | null;
  matured_at: string | null;
  target_progress_percent: number | null;
  interest_rate_bps: number;
  share_count: number;
  status: 'active' | 'dormant' | 'closed';
  opened_at: string;
  updated_at: string;
}

export type TransactionType =
  | 'collection'
  | 'commission'
  | 'withdrawal'
  | 'remittance'
  | 'reversal'
  | 'adjustment'
  | 'penalty'
  | 'savings_interest'
  | 'shares_purchase'
  | 'group_loan_deposit_held'
  | 'group_loan_deposit_refunded'
  | 'group_loan_deposit_applied'
  | 'savings_applied_to_loan_write_off'
  | 'savings_applied_to_group_loan_write_off';

export interface Transaction {
  id: string;
  reference: string;
  /** Known types are labelled; anything newer falls back to a humanized name. */
  type: TransactionType | (string & {});
  status: 'pending' | 'completed' | 'failed' | 'reversed';
  payment_method: 'cash' | 'mobile_money' | 'internal';
  amount: number;
  amount_formatted: string;
  balance_after: number | null;
  /** Money into ('credit') or out of ('debit') this account; null if unknown. */
  direction?: 'credit' | 'debit' | null;
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

export type LoanStatus = 'applied' | 'approved' | 'rejected' | 'disbursed' | 'closed' | 'written_off';

export type InstallmentStatus = 'pending' | 'partially_paid' | 'paid' | 'overdue';

export interface LoanProduct {
  id: string;
  name: string;
  code: string;
  interest_method: 'flat' | 'reducing_balance';
  interest_rate_bps: number;
  term_period_count: number;
  repayment_frequency: 'weekly' | 'monthly';
  origination_fee_amount: number;
  min_amount: number;
  min_amount_formatted: string;
  max_amount: number;
  max_amount_formatted: string;
}

export interface LoanInstallment {
  id: string;
  sequence: number;
  due_date: string;
  principal_due: number;
  interest_due: number;
  penalty_due: number;
  total_due: number;
  total_due_formatted: string;
  amount_paid: number;
  remaining: number;
  status: InstallmentStatus;
  paid_at: string | null;
}

export interface Loan {
  id: string;
  loan_number: string;
  customer_id: string;
  loan_product?: { id: string; name: string };
  principal_amount: number;
  principal_amount_formatted: string;
  interest_method: 'flat' | 'reducing_balance';
  interest_rate_bps: number;
  term_period_count: number;
  repayment_frequency: 'weekly' | 'monthly';
  total_interest: number;
  total_repayable: number;
  total_repayable_formatted: string;
  outstanding_balance: number;
  outstanding_balance_formatted: string;
  status: LoanStatus;
  guarantor_name: string | null;
  guarantor_phone: string | null;
  rejection_reason: string | null;
  installments?: LoanInstallment[];
  applied_at: string | null;
  approved_at: string | null;
  disbursed_at: string | null;
  closed_at: string | null;
  written_off_at: string | null;
  write_off_reason: string | null;
  write_off_amount: number | null;
  write_off_savings_account_id: string | null;
  write_off_savings_applied: number | null;
}

export interface LoanEligibility {
  eligible: boolean;
  reasons: string[];
}

/**
 * Group loans are now one loan per group member (redesigned 2026-09): the
 * member has their own security deposit, their own directly-entered periodic
 * repayment amount, and their own outstanding balance. No product, no
 * interest, no equal-split-across-members.
 */
export type GroupLoanStatus = 'draft' | 'active' | 'closed' | 'written_off' | 'cancelled';

export type GroupLoanDepositStatus = 'pending' | 'held';

export type RepaymentFrequency = 'daily' | 'weekly' | 'monthly';

export interface GroupLoanInstallment {
  id: string;
  sequence: number;
  due_date: string;
  amount_due: number;
  amount_due_formatted: string;
  amount_paid: number;
  amount_paid_formatted: string;
  remaining: number;
  remaining_formatted: string;
  status: InstallmentStatus;
  paid_at: string | null;
}

export interface GroupLoan {
  id: string;
  loan_number: string;
  loan_group_id: string;
  loan_group?: { id: string; name: string };
  loan_group_member_id: string;
  customer_id: string;
  customer_name?: string;
  principal_amount: number;
  principal_amount_formatted: string;
  security_deposit_amount: number;
  security_deposit_amount_formatted: string;
  periodic_amount: number;
  periodic_amount_formatted: string;
  repayment_frequency: RepaymentFrequency;
  start_date: string | null;
  total_periods: number;
  outstanding_balance: number;
  outstanding_balance_formatted: string;
  amount_repaid: number;
  amount_repaid_formatted: string;
  deposit_status: GroupLoanDepositStatus;
  status: GroupLoanStatus;
  installments?: GroupLoanInstallment[];
  issued_at: string | null;
  activated_at: string | null;
  closed_at: string | null;
  written_off_at: string | null;
  write_off_reason: string | null;
  write_off_amount: number | null;
  write_off_savings_account_id: string | null;
  write_off_savings_applied: number | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
}

export interface LoanGroupMember {
  id: string;
  customer_id: string;
  customer_name: string;
  status: 'active' | 'left';
  joined_at: string | null;
  active_loan?: GroupLoan | null;
  /** Draft (awaiting deposit/activation) or active loan; null when the member can be issued a new one. */
  open_loan?: GroupLoan | null;
}

export interface LoanGroup {
  id: string;
  name: string;
  code: string;
  is_active: boolean;
  member_count?: number;
  group_outstanding?: number;
  group_outstanding_formatted?: string;
  members?: LoanGroupMember[];
}

export type GroupStatus = 'draft' | 'active' | 'completed';

export type GroupRoundStatus = 'pending' | 'collecting' | 'completed';

export interface GroupMember {
  id: string;
  customer_id: string;
  customer_name: string;
  rotation_position: number;
  status: 'active' | 'left';
}

export interface GroupRound {
  id: string;
  round_number: number;
  payout_member?: { id: string; customer_id: string; customer_name: string };
  due_date: string;
  total_expected: number;
  total_expected_formatted: string;
  total_collected: number;
  total_collected_formatted: string;
  status: GroupRoundStatus;
  paid_out_at: string | null;
}

export interface Group {
  id: string;
  name: string;
  code: string;
  contribution_amount: number;
  contribution_amount_formatted: string;
  frequency: 'weekly' | 'monthly';
  status: GroupStatus;
  members?: GroupMember[];
  rounds?: GroupRound[];
  activated_at: string | null;
  completed_at: string | null;
}

export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/** Live agent on the branch tracking map (GET /agents/positions). Amounts in minor units. */
export type AgentTrackingStatus = 'active' | 'stale' | 'off_duty';

export interface AgentPosition {
  id: string;
  name: string;
  first_name: string;
  initials: string;
  color: string;
  phone: string | null;
  email: string | null;
  photo_url: string | null;
  status: AgentTrackingStatus;
  on_duty: boolean;
  stale: boolean;
  lat: number;
  lng: number;
  located_at: string | null;
  located_at_human: string | null;
  pings_today: number;
  started_at: string | null;
  collections_count: number;
  collections_total: number;
  collections_total_formatted: string;
}

export interface AgentPositionsResponse {
  branch: { id: string; name: string };
  stale_after_minutes: number;
  data: AgentPosition[];
}

export interface RoutePoint {
  lat: number;
  lng: number;
  accuracy: number | null;
  at: string;
  time: string;
}

export interface RouteStop {
  lat: number;
  lng: number;
  arrived_at: string;
  left_at: string;
  arrived_time: string;
  left_time: string;
  minutes: number;
}

export interface RouteCollection {
  reference: string;
  description: string | null;
  amount: number;
  amount_formatted: string;
  lat: number;
  lng: number;
  at: string;
  time: string;
}

/** One agent's movement for a day (GET /agents/{agent}/route). */
export interface AgentRoute {
  agent: {
    id: string;
    name: string;
    phone: string | null;
    photo_url: string | null;
    on_duty: boolean;
    last_seen_at: string | null;
  };
  date: string;
  points: RoutePoint[];
  stops: RouteStop[];
  collections: RouteCollection[];
  summary: {
    distance_km: number;
    started_at: string | null;
    ended_at: string | null;
    started_time: string | null;
    ended_time: string | null;
    duration_minutes: number;
    points_count: number;
    stops_count: number;
    collections_count: number;
    collections_total: number;
    collections_total_formatted: string;
  };
}
