Ready for review
Select text to add comments on the plan
SusuApp — Full Implementation Plan (offline-first, double-entry, 3 clients)
Context
SusuApp is a multi-tenant susu/micro-finance platform. Agents collect in the field with unreliable internet, and some companies want to operate entirely offline — offline-first is the core design problem.

Platforms & folders (verified on disk)
Platform	Folder	Stack	State
Backend + web admin (source of truth)	c:\xampp\htdocs\Projects\SusuApp	Laravel 12 + Filament v5 + Pest 3 + Sanctum; MySQL susuapp @127.0.0.1:3310	Fresh scaffold; partial tenancy, 5 blocking bugs, 2 empty panels
Mobile (agents + customers)	D:\Mobile\susu-mobile-app	Expo SDK 57, expo-router, TS	Untouched template
Desktop (offline back-office)	D:\Desktop App\susuDesktop	JavaFX 21 / Java 25 / Maven	Hello-world scaffold
Reference implementation	C:\Users\ahmedAhiaFeehi\Documents\NetBeansProjects\Oguaschoolz	JavaFX, proven SQLite/MySQL dual-engine desktop	Working product — port its db layer patterns
Doc fixes (Phase 0): add desktop app to SusuApp/CLAUDE.md; fix susuDesktop/CLAUDE.md's wrong "mobile is always-online" description.

Confirmed scope: all four products (daily susu, loans, target savings, ROSCA); mobile = agents + customers, local-first via WatermelonDB/op-sqlite + sync queue; desktop = configurable SQLite / MySQL / hybrid-sync for offline-operating companies; cash + Paystack momo; notification to customer on every payment; live agent GPS tracking + Uber-style admin map; 4 staff tiers + customer logins; true double-entry ledger; no PWA.

Critical Gap Analysis (what earlier drafts missed → how resolved)
Desktop / offline connection:

G1 — Fully-offline ≠ "mirror + outbox". A company with cloud sync OFF has no server: the desktop must hold the complete operational schema (ledger, customers, accounts, journal) and a full local business engine, not a thin cache. → AD-5 now defines two desktop modes: standalone (full local system) and hybrid (same local system + sync). Same schema/engine either way; sync is just switched on. Loans/ROSCA reach desktop in later phases; core susu is Phase 1.
G2 — Don't invent the DB layer; port Oguaschoolz's. Proven classes to port (rename per package com.ogua.susudesktop): DatabaseProvider.java (interface: getConnection/initialize/close/getType/testConnection), SQLiteProvider (HikariCP pool max 1, WAL + busy_timeout=30000 + foreign_keys embedded in the JDBC URL — property API is silently ignored in DriverManager mode), MySQLProvider (pool 5, explicit-credentials ctor for wizards, hasExistingData()), ProviderFactory (switch on db.type), AppConfig (~/.susudesktop/config.properties, applySQLiteDefaults()/applyMySQLDefaults(), owner-only file perms), DatabaseConnection facade, SessionManager, SetupRecovery. Drop Flyway — port Oguaschoolz's MigrationRunner: V###__desc.sql + manifest.txt in resources, one SQL file set with adaptSqlForMysql() (AUTOINCREMENT→AUTO_INCREMENT, strip CREATE INDEX IF NOT EXISTS, --mysql: prefix for MySQL-only statements), per-file transaction + rollback, pre-migration snapshots (SQLite file copy after wal_checkpoint(FULL); mysqldump for MySQL, both pruned to last 10).
G3 — Offline provisioning & auth. Standalone companies never touch the cloud, so the setup wizard must create the company, branches, chart of accounts, and an initial admin locally (mirrors Oguaschoolz school setup + SetupRecovery). Local users table with bcrypt hashes + login lockout (Oguaschoolz V018 pattern). Hybrid mode: first login online caches token + credential hash for offline re-entry.
G4 — Licensing. An offline desktop is trivially copied; Oguaschoolz solved this with LicenseManager + license/license_checks tables (V010/V017). → Port the pattern (offline activation key per company, periodic checks). Flagged: license policy is a user/business decision — plan includes the mechanism.
G5 — Engine switching & data migration. Companies grow from SQLite → LAN MySQL, or offline → cloud. Port MigrationExportService/OnlineMigrationService patterns: wizard copies data between engines (explicit-credential MySQLProvider ctor exists for exactly this) and a "go online" upload = replay full local journal/master data through /sync/batch (client UUIDs make this idempotent by construction).
G6 — Backups are non-negotiable for money data. Beyond pre-migration snapshots: scheduled daily local backup (SQLite file copy / mysqldump) + manual "Backup now" + restore screen, retention pruning.
G7 — Document-number collisions. customer_code/account_number generated on 3+ client types offline would collide. → Numbers = {branch_code}-{origin_letter}{local_seq} (deterministic, collision-free per device class); server treats them as opaque unique-per-company strings; standalone single-DB companies have no collision surface anyway. UUIDs remain the real identity.
G8 — Clock skew. Offline device clocks drift; recorded_at is client truth, posted_at server truth; skew beyond threshold → meta.flagged_stale, surfaced in a Filament review list (desktop too: sync-status screen).
Backend / mobile / notifications:

G9 — Notification spam on delayed sync. Replaying yesterday's 40 collections at 2am must not send 40 SMS at 2am. → notification_logs unique on (journal_entry_id, channel); quiet-hours window (config per company, default 21:00–07:00 Africa/Accra → queued till morning); entries older than N hours sync-replayed get a single digest SMS option.
G10 — SMS is per-company config. Sender IDs must be registered in Ghana; cost is the company's. → company_sms_settings (provider, sender_id, credentials or platform-pooled billing) — mirrors Oguaschoolz V003. Log driver in dev; provider choice (Arkesel vs Hubtel) flagged.
G11 — Mobile must support on-prem/LAN servers. A company running the Laravel app on a LAN XAMPP box (another offline posture) needs the mobile app to point at it. → API base URL is a runtime setting on the login screen (persisted), not a build-time constant.
G12 — Duty & tracking integrity. Agents could toggle duty off and keep collecting. → RecordCollectionAction requires agent on-duty (server-enforced; offline ops carry duty state and are validated at sync); tracking is duty-scoped for privacy (Act 843). Background location needs an Expo dev build + Android foreground-service notice + iOS always-authorization — called out in mobile Phase 1.
G13 — Sync/locations endpoints need throttling + payload caps (batch ≤ 500 ops, pings ≤ 1/30s effective) — RateLimiter config in Phase 1.
G14 — Timezone/currency. App timezone Africa/Accra; currency fixed GHS (single-country MVP), stored on company for future multi-currency.
Architectural Decisions
AD-1 Money = integer minor units (pesewas) everywhere (PHP Money VO + cast; Java long; TS number). API returns { amount, formatted }.

AD-2 True double-entry ledger. ledger_accounts (chart; type asset/liability/income/expense/equity; nullableUuidMorphs('accountable') sub-accounts: customer savings→liability, agent→cash asset, loan→receivable, group member→liability; cached balance) + journal_entries (reference ULID; client_reference uuid unique = idempotency key; origin web/mobile/desktop/system; type; payment_method; recorded_by; recorded_at device / posted_at server; lat/lng; reversed_entry_id; meta) + journal_lines (debit/credit, one zero). LedgerService::post(): Σdebit=Σcredit, lockForUpdate, atomic cached balances, client_reference dedupe. Append-only; corrections are reversals — this is what makes multi-client offline sync conflict-safe. Postings: collection = Dr agent-cash/Cr customer-liability; day-1 commission = Dr customer-liability/Cr commission-income; remittance = Dr branch-cash/Cr agent-cash; withdrawal = Dr customer-liability/Cr branch-cash; disbursement = Dr receivable/Cr branch-cash; repayment = Dr cash/Cr receivable + Cr interest-income. Agent cash reconciliation = agent cash account balance, free. ProvisionCompanyAction seeds chart; ledger:verify-balances scheduled.

AD-3 One sync protocol, all offline clients. Push POST /api/v1/sync/batch — ops [{op_id uuid, op_type, payload, recorded_at, engine_version}] routed to canonical Actions, per-op try/catch, dedupe on op_id/client_reference, per-op result applied|duplicate|rejected; role-scoped op whitelist (agent set vs desktop back-office set); partial success normal. Pull GET /sync/bootstrap?scope=agent|branch + GET /sync/delta?cursor= (tombstones for soft-deletes). Conflicts: financial = append-only (only duplicates, handled); master data = last-write-wins, server authoritative; stale per G8.

AD-4 Mobile local store = WatermelonDB (reactive mirrors + outbox table), thin DB interface so op-sqlite is a drop-in fallback if Watermelon fights Expo SDK 57. Dev build required regardless (background location). Sync triggers: connectivity listener, foreground, manual; exponential backoff; per-item status UI.

AD-5 Desktop = JavaFX with Oguaschoolz-pattern storage, two modes, one codebase.

Storage profiles (first-run wizard → AppConfig): sqlite (embedded, single machine) | mysql (LAN server, multi-user office — several desktops share it). Orthogonal switch: sync.enabled true/false.
Standalone mode (sync off): full local schema (same V### migrations produce ledger/customers/products/accounts/withdrawals/summaries locally) + full Java operations engine (service package: ledger posting w/ balance enforcement, commission calc, cycle rollover, withdrawals, remittance) — a disciplined parity port of the Laravel Actions, version-stamped (engine_version). Local provisioning + local auth per G3; licensing per G4; backups per G6.
Hybrid mode (sync on): identical local behavior + outbox/sync_state tables; SyncService (JavaFX Service background worker) pushes/pulls per AD-3; server re-validates everything through canonical Actions. "Go online" migration per G5.
Package layout mirrors Oguaschoolz: db, db.provider, db.migration, models, service, utility + FXML screens; pom adds sqlite-jdbc, mysql-connector-j, HikariCP, Jackson, jbcrypt (NOT Flyway).
AD-6 UUIDs everywhere (server HasUuids/v7; clients generate v4 client_reference). foreignUuid()/uuidMorphs().

AD-7 Customer ≠ User. customers = KYC record; nullable unique user_id via ProvisionCustomerLoginAction. One users table, one Sanctum guard, roles + token abilities.

AD-8 Shield/Spatie global roles, no teams (super_admin, company_admin, branch_manager, field_agent, customer); scoping via company_id/branch pivot/policies/tenancy; Spatie migration morph key → uuid.

AD-9 Susu commission = commission_type (first_contribution_per_cycle default | percentage | flat_per_cycle) + value + cycle_length_days on product; CommissionCalculator (ported identically to desktop engine).

AD-10 Notification on every payment. JournalEntryPosted event → queued SendCustomerPaymentNotification for every customer-affecting movement (all origins incl. sync replay + momo webhook). SMS via SmsService driver contract + database channel; push later. Dedupe + quiet hours + digest per G9; per-company settings per G10; notification_logs audit.

AD-11 Agent tracking + live map. agent_location_pings (batch) + agent_live_positions (row per agent). Duty-scoped (day-open starts background task, day-close stops; server enforces duty for collections per G12). Mobile: expo-location + expo-task-manager (~60s/100m), pings buffered offline in outbox. Filament AgentTrackingMap page: Leaflet + OSM tiles, all on-duty agents in tenant, click → zoom + panel (photo, name, phone, last-seen, today's count/total, day trail), stale greyed, wire:poll 10s → Reverb upgrade path later.

AD-12 Soft deletes on master data only. Never on journal/installments/rounds/pings.

AD-13 Paystack: remove both installed packages; thin PaystackClient (Http facade; momo charge, verify, HMAC-SHA512 webhook). Platform key MVP; subaccounts later.

AD-14 Enums in app/Enums: TransactionType, EntryStatus, PaymentMethod, LedgerAccountType, AccountStatus, LoanStatus, InstallmentStatus, WithdrawalStatus, InterestMethod, CommissionType, GroupRoundStatus, SyncOpType, ClientOrigin.

AD-15 Layering: Filament, API controllers, and sync dispatcher all call the same app/Actions/*.

AD-16 Compliance: Ghana Card KYC + next of kin; id_number encrypted cast; KYC photos private disk; location data staff-only/duty-scoped (Act 843); immutable journal + trial balance support BoG-style reporting (Act 987 licensing = operator's task); activitylog on master data (dep needs approval).

Phase 0 — Foundations (panels + API auth + both client shells)
Backend bug fixes (before any real migrate):

branch_users migration: foreignIdFor() bigint vs uuid PKs → foreignUuid().
personal_access_tokens migration: morphs → uuidMorphs (blocks all Sanctum auth).
User.php: HasUuids, company()/branch(), fillable + phone (unique/company) + photo_path.
branches migration: add slug + unique(company_id, slug); flesh out Branch/Company models.
AdminPanelProvider.php: undefined Auth::guard('admin') → default guard.
composer remove both Paystack packages; add pest-plugin-livewire (+ activitylog w/ approval); publish Spatie migration (uuid morph key).
Backend build: Shield both panels; RoleSeeder/SuperAdminSeeder/DemoSeeder; Company/Branch factories + UserFactory role states; routes/api/v1.php; Auth/AuthController (login by email-or-phone, logout, me) + LoginRequest; role: middleware alias; timezone Africa/Accra; CLAUDE.md updates (both repos); tests (Auth/Tenancy/PanelAccess).

Mobile shell: axios, react-query v5, zustand, expo-secure-store, WatermelonDB (dev build; op-sqlite fallback), expo-network/location/task-manager. src/api/client.ts (base URL runtime-configurable on login screen, G11), authStore, src/db/ schema v1 + thin DB interface, src/sync/ outbox engine scaffold, (auth)/login, (agent)/+(customer)/ groups.

Desktop shell (D:\Desktop App\susuDesktop): pom deps (sqlite-jdbc, mysql-connector-j, HikariCP, Jackson, jbcrypt); port from Oguaschoolz: AppConfig (→ ~/.susudesktop/), db.provider.* (all four classes), db.migration.MigrationRunner (+ manifest + V001 base schema: local_users w/ lockout, config, sync_state, outbox, license), DatabaseConnection, SessionManager, SetupRecovery; first-run wizard FXML (profile: SQLite/MySQL; mode: standalone→local company+admin provisioning | hybrid→cloud login+bootstrap); main shell (nav, TilesFX dashboard placeholder, sync/status bar); JUnit on both engines (in-memory sqlite + optional local MySQL).

Phase 1 — Ledger + Customers + Daily Susu + Sync + Tracking + Notifications
Backend tables: AD-2 trio; customers (KYC per AD-16 + next-of-kin + client_reference unique + softDeletes); savings_products; savings_accounts (agent_id, ledger_account_id, cycle fields, cached balance, account_number per G7 scheme); withdrawal_requests; agent_daily_summaries; sync_ops (op_id unique, origin, actor, type, status, result — dedupe/audit); agent_location_pings + agent_live_positions; notification_logs (unique journal_entry_id+channel); company_sms_settings. Factories all (withBalance() posts real entries).

Services/Actions/Events: LedgerService (first, with tests), ChartOfAccounts, CommissionCalculator, SmsService (driver contract + log driver); Actions: ProvisionCompany, Create/UpdateCustomer, ProvisionCustomerLogin, OpenSavingsAccount, RecordCollectionAction (assignment + duty check, posting, rollover, commission, idempotent), Request/Approve/Reject/PayWithdrawal, SubmitAgentDailySummary/ReconcileAgentDay/RecordAgentRemittance, RecordLocationPings, Sync\ProcessSyncBatchAction (role-scoped router); JournalEntryPosted → SendCustomerPaymentNotification (quiet hours/dedupe/digest per G9).

API v1: /sync/batch|bootstrap|delta (throttled per G13); agent: accounts, customers (GET/POST), collections, summary/today, summaries, remittances, locations, duty on/off; customer: accounts, transactions, withdrawal-requests. Resources: Customer, SavingsAccount, JournalEntry("transaction" shape), WithdrawalRequest, AgentDailySummary, SyncResult, AgentLivePosition.

Requests/Policies: Form Requests per mutation incl. SyncBatch + LocationPings; policies replace deny-all stubs (approve/reconcile/reverse = branch_manager+; JournalEntry immutable); shield:generate --all.

Filament: Customer (KYC form), SavingsProduct (company-level), SavingsAccount (+entries RM, Record Collection), WithdrawalRequest (Approve/Reject/Pay), JournalEntry (read-only + gated Reverse + stale-sync review filter G8), AgentDailySummary (Reconcile), LedgerAccount (chart, read-only), Pages/AgentTrackingMap (AD-11); SuperAdmin: Company/Branch/User + impersonate.

Tests: LedgerServiceTest (balance/reversal/dedupe), CommissionCalculatorTest, CollectionTest (posting, day-1 commission, rollover, cross-branch denied, notification queued once), SyncBatchTest (replay=duplicate; partial success; role scoping; stale flag), WithdrawalFlowTest, AgentSummaryTest (expected=ledger), LocationPingTest (duty gating), NotificationQuietHoursTest, Filament tests + map page render.

Mobile: agent offline-first (bootstrap→local DB; dashboard w/ sync badge; local account search; collect = outbox-first + GPS; register customer offline; day open/close = duty toggle + location task; sync screen w/ retry); customer online (accounts, history, withdraw).

Desktop: V002+ migrations (full core-susu schema local); operations engine (ledger post/commission/rollover/withdrawal/remittance — parity port, engine_version); screens: customers/KYC, open account, record collection, withdrawals, remittance, dashboard tiles; SyncService wired (hybrid); backup service + restore screen (G6); works in sqlite/mysql/standalone/hybrid.

Phase 2 — Loans
loan_products (rate bps, flat/reducing, term, frequency, fees, penalty, grace), loans (snapshots, outstanding, receivable account, status flow, guarantor meta), loan_installments. InterestCalculator + ScheduleGenerator (pure), EligibilityService (susu history: account age, consistency %, avg balance). Actions: Apply/Approve/Reject/Disburse/RecordRepayment (sync-op capable) + loans:flag-arrears. Filament LoanProduct/Loan (+installments RM, eligibility panel) + ArrearsReport. API agent/customer loan endpoints + sync ops. Notifications: disbursement/repayment/installment-due. Tests incl. offline repayment replay. Mobile agent/customer loan screens. Desktop: loan screens on engine (flag: approval rights when standalone — company_admin local role approves; hybrid = server-side approval recommended).

Phase 3 — Target Savings + ROSCA
Target: product type + target/penalty columns, target_amount/matures_at, maturity action + command, progress everywhere. ROSCA: groups/group_members (rotation_position, liability account)/group_rounds (payout member, payout_entry_id)/group_contributions (unique round+member, client_reference). Actions incl. ActivateGroup (round generation), RecordGroupContribution (sync-op), PayoutGroupRound (gate or override). Filament GroupResource + contribution matrix; API both roles; payout/contribution notifications; rotation e2e + offline sync tests; mobile grid + customer groups; desktop group screens.

Phase 4 — Paystack Momo (two flows, any platform)
Two payment flows, company-configurable (company_payment_settings.momo_flow: charge_api | checkout | both):

Charge API (primary, agent + customer + web). Initiator enters the paying momo number + network → PaystackClient::chargeMobileMoney() (POST /charge, mobile_money channel) → PIN prompt appears on the customer's phone → app immediately navigates to a Verify Payment screen: fast client polling of GET /payments/{intent} (every 3s, ~90s window) while the server races POST /payments/{intent}/verify (direct Paystack verify) against the webhook — whichever confirms first posts the ledger entry; both are idempotent on the intent so no double-post. Handles Paystack's intermediate states: pay_offline (waiting for PIN), send_otp/voucher (Telecel) via POST /payments/{intent}/submit-otp, failed/abandoned with clear retry.
Hosted checkout (fallback/flexible). Initialize Transaction → authorization_url opened in-app (expo-web-browser) or new tab on web → callback → same verify screen. Useful when Charge API misbehaves for a network or for card/other channels later.
Where it surfaces: Agent collect screen gains payment-method choice Cash | Mobile Money (momo requires online; greyed offline) — momo success posts through the same RecordCollectionAction with payment_method=mobile_money (commission logic identical). Customer app deposit screen; Filament "Record Collection" action gets the same choice (charge initiated from web, verify panel polls). Loan repayments + group contributions reuse the same intent flow via payable morphs.

Backend: PaystackClient (charge, submit_otp, verify, initialize, HMAC-SHA512 webhook check); payment_intents (payable morphs, provider_reference unique, flow, channel mtn/telecel/airteltigo, phone, status initiated/pay_offline/send_otp/pending/success/failed/abandoned, journal_entry_id, raw_response); Actions: InitiateMobileMoneyChargeAction, SubmitChargeOtpAction, VerifyPaymentIntentAction, HandlePaystackWebhookAction (idempotent → canonical Record* actions → same customer notifications), payments:reconcile for stale intents. Routes: webhook (signature middleware, queued job) + initiate/verify/submit-otp/poll for both agent and customer roles. Filament PaymentIntentResource + verify panel. Tests: charge state machine (Http::fake sequences for pay_offline→success, send_otp path, timeout), webhook/verify race idempotency, signature, ledger mapping. Mobile: shared PaymentVerifyScreen used by agent collect and customer deposit; desktop hybrid shows intents read-only.

Phase 5+ — Reporting, Push, Polish
Daily collections per agent/branch, defaulters, HQ cash reconciliation, trial balance, statement PDF (dompdf), Excel exports; dashboard widgets (CollectionsToday/ActiveAccounts/PAR/AgentLeaderboard/CashInField + platform widgets); expo push + device tokens; Reverb websockets for map + trail playback; activitylog UI; KYC retention command; desktop report parity + engine-switch/“go online” wizards (G5) + license admin (G4).

Recommended additional features (not yet committed — pick and I'll slot them into phases)
High value for Ghanaian susu operations:

Recurring momo auto-debit ("susu direct") — customer authorizes once; Paystack charges the saved authorization daily/weekly automatically. Removes the agent visit for digital-first customers; huge differentiator. (Fits Phase 4+ as a scheduled command over saved authorizations.)
Bluetooth thermal receipt printing from the agent app (ESC/POS over BLE) — susu customers expect a paper receipt; works offline.
Customer QR passbook cards — printed card with QR (account uuid); agent scans to open the collect screen instantly. Faster rounds, fewer wrong-account errors.
Duplicate/fraud controls — flag same-account multiple collections per day, collections recorded far from customer's usual GPS cluster, excessive reversals per agent, backdating patterns. Cheap to build on the ledger + pings you already have.
Agent cash-limit alerts — company sets max cash-in-hand; agent + manager notified when the ledger balance crosses it (robbery/misappropriation risk control).
WhatsApp notifications (Meta Cloud API) as a cheaper channel beside SMS, with per-company channel preference.
Ghana Card verification (NIA API) hook in KYC — verify ID number at registration when online; stored verification status.
Back-office completeness (you have proven code for these in Oguaschoolz): 8. Branch expenses / petty cash module (port ExpenseService concepts onto the double-entry ledger — expense accounts already exist in the chart). 9. Agent commission payroll — periodic payout run computing agent earnings (per-collection commission share), posting salary/commission entries (PayrollService precedent). 10. End-of-day branch vault management — branch cash position, bank deposits recording (Dr bank / Cr branch-cash).

Growth/UX: 11. Biometric/PIN unlock for the agent app (expo-local-authentication) — field devices get shared/stolen. 12. Twi/Fante localization of the mobile app + SMS templates. 13. Customer statement via SMS/WhatsApp keyword ("BAL" → balance reply) — serves non-smartphone customers. 14. Dormancy & churn analytics — auto-dormant accounts, reactivation campaigns, defaulter heat by agent/area.

Verification (every phase)
php artisan migrate:fresh --seed; php artisan test --compact; vendor/bin/pint --dirty --format agent.
ledger:verify-balances zero discrepancies; trial balance nets to zero.
Web: /admin + /super-admin flows; Boost database-query/browser-logs.
Mobile: dev build; airplane-mode test — collect offline, kill/reopen (outbox persists), reconnect → server ledger + single notification; replay → no double-post; duty toggle → marker on admin map.
Desktop: mvn clean javafx:run + JUnit both engines; test 4 matrix cells (sqlite/mysql × standalone/hybrid); hybrid: record offline → sync → verify server; standalone: full day cycle + backup/restore; migration runner snapshot present.
Notifications: every payment path (cash, sync replay, momo) → exactly one log entry; quiet-hours queuing works.
Flags needing user decisions (as they arrive)
License policy for offline desktop (mechanism planned per Oguaschoolz LicenseManager; pricing/enforcement = business call).
SMS provider (Arkesel vs Hubtel) + sender ID registration before production.
Standalone-desktop loan approval rights (Phase 2).
Dependency approvals: activitylog; WatermelonDB dev-build (op-sqlite fallback); Paystack package removal.
Add Comment