# SusuApp Mobile — UI/UX Redesign Prompt (corrected for this codebase)

> This replaces the generic "Full React Native Expo Susu App — UI/UX Audit" prompt.
> The original was written without seeing the code. It assumed features, roles,
> folders and tooling that don't exist, and it left out constraints that do.
> Everything below is checked against the repo (2026-10-05).

## What was wrong with the original prompt

| Original assumption | Reality in this repo | Correction |
|---|---|---|
| Roles might include collector, supervisor, accountant, business owner | `Role` = `super_admin`, `company_admin`, `branch_manager`, `field_agent`, `customer` ([src/types/api.ts](../../src/types/api.ts)). Staff share the `(agent)` route group; customers get `(customer)`. | Audit two experiences, **staff** and **customer**. Inside staff, gate manager-only actions (write-off, payout override) by role. Admin dashboards stay on the web (Filament). |
| Registration, PIN setup/unlock, OTP login, password reset, activation | Only `/auth/login` (email/phone + password) and a configurable server URL. | Redesign **login only**. PIN/biometric unlock and password reset are **new features**: they need backend endpoints plus web/desktop parity, so list them in the plan and don't build them here. |
| Collection routes, schedules, missed contributions, arrears, notifications, receipts API | None of these endpoints exist. The agent sees a flat, searchable account list. Sync results return no transaction reference. | Don't invent them. Design with the data that exists, e.g. a receipt built from the data the agent just entered plus the local op reference. List the missing API work as backend items. |
| "Contribution" terminology | Backend and UI say **collection** (agent cash-in), **deposit** (customer self-service MoMo), **withdrawal request**, **commission**, **remittance**, **reversal**, **adjustment**. | Use the existing terms. |
| "Offline-first if supported" | **Hybrid**: agent writes (`collection.record`, `customer.register`, `account.open`, `summary.submit`, `loan.write_off`, location pings) go to a SQLite outbox ([src/sync](../../src/sync)) and drain to `POST /sync/batch`. Reads are online, and the dashboard is cached. AGENTS.md wrongly says "always-online; no local database". | Treat sync status as first-class UI. Fix the AGENTS.md wording. |
| Double-submit: "use idempotent backend behavior where supported" | The backend **already** accepts a `client_reference` UUID on collections, loan/group-loan repayments, deposits, group contributions, loan applications and MoMo charges, and returns the existing entry on a repeat. The mobile app only sends it through the outbox. | Send a per-attempt `client_reference` on every online financial write, and lock the UI after success. Withdrawal requests and group-loan write-off have **no** key, which is a backend gap. |
| Folders `src/services`, `src/screens/*`, local DB of business data | `src/api`, `src/app` (expo-router), `src/components/ui`, `src/sync`, `src/db` (outbox only), `src/stores` (zustand), `src/screens` (2 shared screens). | Use the real layout. |
| `KeyboardAwareScrollView` | Not installed (`react-native-keyboard-controller` absent). | Use RN `KeyboardAvoidingView` + `ScrollView` with `keyboardShouldPersistTaps="handled"` and `automaticallyAdjustKeyboardInsets` (iOS), built into one `Screen` primitive. Add a library only if the built-in approach fails on a device. |
| QA: `npm test` | No test runner configured. | QA = `npx tsc --noEmit`, `npm run lint`, `npx expo export --platform android`. |
| Multi-currency caution | All amounts are integer pesewas. The API sends `*_formatted` strings (`GHS 1,234.50`). | Show API-formatted strings where they exist. For client-side display, use one `formatMoney()` with `GH₵` and thousands separators. Never do money math in floats. |
| Charts "where appropriate" | `react-native-gifted-charts` is installed and used for 30-day trends. | Keep it. Add labels/axes, an empty state, and a fallback for stale/offline data. |
| Feature parity unspecified | AGENTS.md: any **user-facing feature** must reach web (Filament) and desktop (JavaFX). | Visual-only changes need no parity. New features (PIN, receipts sharing, notifications) do. |
| Expo version unspecified | Expo SDK **57**, RN 0.86, React 19.2, React Compiler on, typed routes, `expo-symbols` (SF Symbols iOS, Material Symbols Android/web, bundled offline). | Read https://docs.expo.dev/versions/v57.0.0/ before using any Expo API. |

## The prompt

You are working on **SusuApp mobile** (brand: **OguaFinance**), the Expo SDK 57 client of a Laravel 12 susu / micro-finance backend. It is a thin client: business rules, balances and validation live on the server. Your job is a product-level UI/UX redesign that makes the app feel like a trustworthy fintech product, **without changing business logic, API contracts, auth, permissions, or the sync/outbox behaviour**.

### Read first
`AGENTS.md`, `CLAUDE.md`, `.claude/reference/*`, `src/types/api.ts`, `src/api/*`, `src/sync/*`, `src/stores/*`, `src/constants/theme.ts`, `src/components/ui/*`, and every route under `src/app`.

### Users
1. **Field agent** (`field_agent`): records cash collections hundreds of times a day, often offline. Also registers customers, opens accounts, declares end-of-day cash, records loan and group-loan repayments, and records susu-group contributions. Speed and certainty that a collection was saved matter most.
2. **Branch manager / company admin** (same staff UI) can also write off loans and pay out group rounds (including early override). These are destructive actions that need explicit confirmation.
3. **Customer**: views balances and transactions, requests withdrawals, deposits or buys shares via mobile money, applies for loans, and views groups. Needs to understand their financial position clearly.

### Non-negotiables
- No business logic on the client. Don't compute balances, fees, commission or "new balance after collection". Show what the server returns, or say plainly that the server hasn't confirmed it yet.
- Every financial submit is protected against double-tap. The button is disabled while in flight **and stays locked after success**. Online writes carry a `client_reference` UUID that is reused on retry of the same attempt and regenerated after success.
- Never claim success the server hasn't confirmed. Outbox writes say **"Saved on this device · waiting to sync"** until the sync result arrives.
- Never show raw axios, network or server text to customers. Map errors to clear product messages. Validation messages from the API (422) are written for users and may be shown.
- Destructive or irreversible actions (write-off, early payout, withdrawal request) need a confirmation that names the **customer, amount and consequence**, with a specific button label ("Write off GH₵ 1,200.00").
- Money uses tabular figures, a `GH₵` prefix and thousands separators. Credits and debits are distinguished by **sign + label + icon**, not colour alone.
- Respect safe areas through one shared `Screen` primitive. Every form scrolls with the keyboard open and has its primary action reachable.
- Lists of accounts, transactions and installments are virtualised (`FlatList`).
- Works in light and dark mode. No hard-coded `#ffffff` surfaces.

### Deliverables, in order
1. `.claude/reference/susu-mobile-ui-ux-audit.md`: screen inventory (route, role, purpose, issues, priority), role audit, financial-workflow audit, keyboard/safe-area/a11y/performance audit, scores, backend gaps.
2. `.claude/reference/susu-mobile-ui-redesign-plan.md`: phased plan with each phase's QA gate, marking what is done and what is pending (including backend/web/desktop work).
3. Design system in `src/constants/theme.ts`: tokens for colour (light/dark), type (incl. a money style), spacing, radii, elevation.
4. Shared components in `src/components/ui`: `Screen`, `Icon`, `Button`, `Input`, `AmountInput`, `Money`, `Card`, `Badge`, `ListRow`, `SectionHeader`, `SegmentedControl`, `ChipSelect`, `StatTile`, `BalanceCard`, `TransactionRow`, `EmptyState`, `ErrorState`, `LoadingState`, `OfflineBanner`, `SyncStatusPill`, `ResultSheet` (success/receipt), `confirmAction`.
5. Screen redesign, phase by phase: Login → Agent home → Account search → Collect + receipt → Day close → Sync queue → Register / Open account → Customer home → Account detail / history → Withdraw / Deposit / Buy shares / Payment verify → Loans, groups, group loans.
6. QA after each phase: `npx tsc --noEmit`, `npm run lint` (no new errors), and finally `npx expo export --platform android`.

### Out of scope here (record in the plan as backend + web + desktop work)
PIN/biometric app lock, password reset, collection routes / today's expected list, missed-contribution tracking, push notifications, server-issued receipts with transaction reference and post-transaction balance, `client_reference` on withdrawal requests and group-loan write-off, server-side sharing/printing of receipts.
