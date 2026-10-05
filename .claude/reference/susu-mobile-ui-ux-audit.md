# SusuApp Mobile — UI/UX Audit

Audited: 2026-10-05 · Expo SDK 57 · 31 route files + 2 shared screens · ~6.7k LOC.
Baseline QA: `tsc` clean; `lint` has 1 error (`use-color-scheme.web.ts`, set-state-in-effect) and 4 warnings; no test runner.

## Executive summary

The app is functionally broad (collections, customer onboarding, savings products, loans, susu groups, group loans, mobile money, day close, offline outbox), and its architecture is sound: a thin API client, typed resources, and an idempotent outbox. The UI is roughly half migrated. About 12 screens use a small design system (`Screen`, `Card`, `Button`, `Input`, `Badge`), while the loan, group, group-loan and payment-verify screens still use hand-rolled `Pressable`/`TextInput` styling with hard-coded `#ffffff` cards, so they break in dark mode.

The most serious problems are **financial-safety bugs**, not visual ones:

1. **Duplicate collections (Critical).** On cash collect, the submit button re-enables in `finally` while the screen waits 900 ms before going back. A second tap enqueues a second collection with a new UUID, so the server's idempotency can't dedupe it. The same pattern affects day close, open account and withdrawal request.
2. **Online financial writes send no idempotency key (High).** Loan repayments, group-loan deposit/repayment, group contributions, MoMo charges and loan applications go straight to the API without `client_reference`, even though the backend supports it. A timeout followed by a retry can double-post.
3. **No confirmation on irreversible actions (High).** Group-loan write-off fires on one tap. Loan write-off fires on one tap. Group payout (non-override) fires on one tap. Group contribution posts on one tap per member.
4. **Ambiguous success.** "Collection saved. It will sync automatically." disappears after 900 ms. There's no receipt, and the agent can't show the customer anything.
5. **Raw errors leak.** `sync.tsx` shows server `last_error` strings verbatim, and `apiErrorMessage` falls back to transport text.

Visually, the app reads as a developer prototype: a text-only nav grid, no icons anywhere, a single flat blue, no money typography, `toLocaleString()` dates, a "Sign out" button on the dashboard body, and no safe-area handling under the native header for bottom actions.

## Design scores (1–10, before)

| Area | Score | Note |
|---|---|---|
| Visual design | 4 | Clean but generic; no icons, flat hierarchy |
| UX | 5 | Flows exist and are short; feedback is weak |
| Trustworthiness | 4 | No receipts, transient success text, raw errors |
| Financial clarity | 4 | "Balance" unlabeled; credits only colour-coded |
| Collection workflow | 4 | Fast, but double-submit bug and no receipt |
| Customer workflow | 5 | Balance visible; history rows thin |
| Collector workflow | 5 | Search is good; Loan/Statement pills crowd rows |
| Dashboard | 5 | Useful numbers; chart unlabeled; nav grid text-only |
| Navigation | 5 | Native stack; dashboard is the only hub |
| Forms | 4 | Two styling systems; YYYY-MM-DD free-text dates |
| Keyboard handling | 3 | Only login uses KAV; long forms rely on luck |
| Safe area | 5 | Native header covers the top; bottoms unhandled on edge-to-edge Android |
| Accessibility | 3 | No labels on toggles/chips; colour-only status |
| Offline UX | 6 | Outbox is real; banner/state missing |
| Sync UX | 5 | Toast + queue screen; op types shown raw (`collection · record`) |
| Loading states | 4 | Bare spinners |
| Empty states | 5 | Exists on half the screens |
| Error states | 4 | Mixed; some raw |
| Animation | 2 | None |
| Performance | 7 | FlatLists used; search fires a query per keystroke (no debounce) |
| Consistency | 3 | Two component systems |

## Roles

| Role | Route group | What they do on mobile | Notes |
|---|---|---|---|
| `field_agent` | `(agent)` | Collect, register, open account, day close, loans, group contributions, group-loan deposit/repayment/issue | Primary power user; offline |
| `branch_manager`, `company_admin` | `(agent)` | All of the above, plus loan write-off, group payout/override, group-loan write-off | Destructive actions need confirmations |
| `super_admin` | `(agent)` | Platform role; normally uses the web | No special mobile UI needed |
| `customer` | `(customer)` | Balances, history, statement, withdraw request, MoMo deposit, buy shares, loans, groups | Online-first |

A collector's UI shouldn't be identical to an administrator's. Today it is identical apart from the write-off/payout cards. That's acceptable on mobile because management dashboards live on the web, but the dashboard should show the role badge and the manager-only actions must be visually distinct (destructive styling + confirm).

## Screen inventory

Priority = redesign priority (C/H/M/L).

| Route | Role | Purpose | Key issues | Pri |
|---|---|---|---|---|
| `(auth)/login` | all | Sign in, set server URL | Placeholder "O" logo; no password visibility toggle; error sits under password only; server toggle unclear | H |
| `(agent)/index` | staff | Dashboard: duty, today's totals, 30-day chart, nav | Text-only nav grid; unlabeled chart; sign-out in body; no offline/pending indicator except "(n)" suffix | H |
| `(agent)/accounts` | staff | Search accounts → collect | Unlabeled "balance"; Loan/Statement pills crowd every row; no debounce; empty state not search-aware | H |
| `(agent)/collect/[accountId]` | staff | Record cash / MoMo collection | **Double-submit bug**; no receipt; amount not formatted; MoMo has no idempotency key | **C** |
| `(agent)/day-close` | staff | Declare cash | **Double-submit**; no variance preview; declared vs expected unclear | H |
| `(agent)/sync` | staff | Outbox list + retry | Raw op types and server errors; no counts/last sync | H |
| `(agent)/register-customer` | staff | Offline customer registration | Long form, no KAV; YYYY-MM-DD text dates; error at bottom far from field | M |
| `(agent)/open-account` | staff | Offline account opening | **Double-submit**; chips unlabeled for type | M |
| `(agent)/payment-verify` | staff | MoMo polling/OTP | Hard-coded white card (dark mode broken); no safe area; raw spinner | H |
| `(agent)/loans/index` | staff | Loan list | Raw styling; colour-only status | M |
| `(agent)/loans/[loanId]` | staff/customer | Loan detail, repayment, write-off | Hard-coded white; **write-off without confirm**; repayment without idempotency | H |
| `(agent)/loans/apply/[accountId]` | staff | Loan application | Raw styling; eligibility card fixed light colours | M |
| `(agent)/groups/index` | staff | Susu groups | Raw styling | L |
| `(agent)/groups/[groupId]` | staff | Contributions + payout | **Contribute = one tap posts money**, no confirm or idempotency; white cards | H |
| `(agent)/group-loans/index` | staff | Group loans | Raw styling | L |
| `(agent)/group-loans/[groupLoanId]` | staff | Deposit, activate, repay, write-off | **Write-off/activate without confirm**; no idempotency; white cards | H |
| `(agent)/group-loans/apply` | staff | Issue member loan | Raw styling; free-text date | M |
| `(customer)/index` | customer | Total savings, charts, accounts | "Total savings" label OK; balance card weak; sign-out in body; donut has no legend | H |
| `(customer)/account/[accountId]` | customer | Account detail + history | No balance header; 4 stacked buttons; history lacks balance-after/reference; credit shown by colour only; commission shown as "+" | H |
| `(customer)/withdraw` | customer | Withdrawal request | **Double-submit window**; no confirm; no balance shown | H |
| `(customer)/deposit` | customer | MoMo deposit | No idempotency key | M |
| `(customer)/buy-shares` | customer | MoMo share purchase | No idempotency key | M |
| `(customer)/payment-verify` | customer | Same as agent | See above | H |
| `(customer)/loans/*` | customer | Loans list/apply/detail | Raw styling | M |
| `(customer)/groups/*` | customer | Group list/detail | Raw styling, white cards | L |

## Critical financial workflows

| Workflow | Today | Risk | Fix |
|---|---|---|---|
| Cash collection | Outbox enqueue → toast-ish text → auto back after 900 ms | Duplicate on double tap; no proof for the customer | Lock after success; full-screen result with receipt (customer, account, amount, method, time, device reference, sync state); "Next customer" returns to search |
| MoMo collection / deposit / shares | Charge → verify screen | Retry after timeout double-charges | Per-attempt `client_reference`; verify screen redesign |
| Withdrawal request | Online POST | Duplicate request in the 900 ms window; no confirm | Confirm with amount and account; lock after success. Backend gap: no `client_reference` |
| Loan repayment | Online POST | Retry double-posts | `client_reference` + confirm |
| Group contribution | One tap per member posts | Mis-tap posts money | Confirm naming member + amount; `client_reference` |
| Group payout | One tap | Irreversible | Confirm with amount + recipient |
| Loan / group-loan write-off | One tap | Irreversible | Destructive confirm with amount + customer |
| Day close | Outbox | Duplicate declaration | Lock after success; show variance preview (display-only subtraction of server values) |
| Reversal / adjustment | Not on mobile (web only) | — | Show them clearly in history with a label |
| Receipt | Doesn't exist | — | Client-side receipt from known data (no invented balance) |
| Reconciliation | Day close only | — | — |

## Keyboard audit

| Screen | Inputs | Problem |
|---|---|---|
| login | 2–3 | KAV present but `undefined` behaviour on Android; card isn't scrollable on small screens with server field open |
| collect | amount, phone | Submit can sit under keyboard on small Android with MoMo fields |
| day-close | amount, multiline notes | Button covered by keyboard |
| register-customer | 11 fields | No KAV; lower fields covered on iOS |
| open-account | 3 | Same |
| withdraw / deposit / buy-shares | 2–3 | Same; multiline reason |
| loan detail / group-loan detail | amount, reason (multiline) inside FlatList header | Write-off reason covered |
| loans/apply, group-loans/apply | 4–6 | No KAV |
| payment-verify | OTP | Centered card; keyboard covers Submit on small phones |
| accounts | search | Fine; `keyboardShouldPersistTaps` missing, so the first tap on a result only dismisses the keyboard |

**Strategy:** one `Screen` primitive with `KeyboardAvoidingView` (iOS `padding`; Android relies on edge-to-edge `adjustResize`) wrapping a `ScrollView` with `keyboardShouldPersistTaps="handled"`, `keyboardDismissMode="interactive"` (iOS) / `on-drag`, and `automaticallyAdjustKeyboardInsets` on iOS. FlatList screens set `keyboardShouldPersistTaps="handled"` themselves.

## Safe area audit

Native stack headers handle the top inset on all `(agent)`/`(customer)` screens. Issues:
- Login uses `SafeAreaView` correctly.
- **Bottom inset** is never applied. With Android edge-to-edge (SDK 57 default), the last button or row sits under the gesture bar on every scroll screen. Fix: `Screen` adds `insets.bottom` to content padding.
- `SyncToast` is absolutely positioned at `bottom: 32`, ignoring the inset, so it overlaps the gesture bar.
- `payment-verify` has no safe-area-aware container.

## Accessibility audit

- Status relies on colour alone (loan lists use only coloured status text; transactions show only red/green amounts).
- Toggles and chips have no `accessibilityRole`/`accessibilityState` (selected).
- The duty `Switch` has no label.
- Icon-only controls: none yet. Every new one must get an `accessibilityLabel`.
- Touch targets: list pills are ~32 px tall (below 44/48).
- `textSecondary` #6b7280 on #f6f7f9 ≈ 4.6:1, which passes, but barely.
- Font scaling: fixed `lineHeight` with large money values can clip. Money text should allow `adjustsFontSizeToFit` on the hero balance.

## Performance audit

- Account search fires a request per keystroke. Debounce it to 300 ms and keep previous data while typing (`placeholderData`).
- Inline `renderItem` + `ItemSeparatorComponent` arrow functions are acceptable with React Compiler on.
- Transaction history fetches page 1 only. Add infinite scroll via `useInfiniteQuery` (API is paginated).
- Charts render 30 points, which is fine.
- `SavingsAccountPicker` refetches customer per mount; it's cached by react-query key, so fine.

## Design system (recommended, now implemented)

- **Colour:** a deep navy/indigo primary for trust (`#1849A9` family, keeping the OguaFinance blue as the accent), emerald for success/credits, amber for pending/warning, red for destructive/debits, slate neutrals. Full light/dark semantic tokens: `background`, `surface`, `surfaceMuted`, `text`, `textSecondary`, `textMuted`, `border`, `primary`, `primarySoft`, `onPrimary`, `success(+Soft)`, `warning(+Soft)`, `danger(+Soft)`, `info(+Soft)`.
- **Type:** display 32/38, title 24/30, heading 18/24, body 16/24, label 14/20, caption 12/16, plus `money` styles with `fontVariant: ['tabular-nums']`.
- **Spacing:** 4-pt grid (existing). **Radii:** sm 8 / md 12 / lg 16 / xl 24 / pill. **Elevation:** one soft level for cards, one for floating elements.

## Component architecture

`Screen` (safe area + keyboard + scroll/refresh), `Icon` (expo-symbols wrapper with an iOS/Android name map), `Button` (variants + leading icon + `size`), `Input` (label, hint, error, trailing accessory, password toggle), `AmountInput` (GH₵ prefix, sanitising, large tabular display), `Money` (signed, tabular), `Card`, `Badge` (icon + text), `ListRow`, `SectionHeader`, `SegmentedControl`, `ChipSelect`, `StatTile`, `BalanceCard` (hero gradient), `TransactionRow`, `EmptyState`/`ErrorState`/`LoadingState`, `OfflineBanner`, `SyncStatusPill`, `ResultView` (success/receipt), `confirmAction` (native Alert with explicit labels). Hooks: `useIdempotencyKey`, `useOnline`, `usePendingCount`.

## Visual improvements

- Icons everywhere navigation or status is shown (Material Symbols / SF Symbols, offline-bundled).
- Gradient hero balance card (`expo-linear-gradient`, already installed).
- Labelled charts with an offline "last updated" stamp.
- Subtle entering animations (Reanimated `FadeInDown`, ≤ 250 ms) on dashboard cards and the success check. Nothing blocks input.
- No stock illustrations: icon-in-circle empty states keep the bundle small and work offline.

## Backend / parity gaps (do not fake on mobile)

1. `client_reference` on `POST /customer/withdrawal-requests` and `POST /group-loans/{id}/write-off`.
2. `/sync/batch` result should return the created transaction `reference` and `balance_after` for `collection.record` so receipts can show the server reference and new balance.
3. Agent "today's expected collections" / route list (needs a schedule concept).
4. PIN/biometric unlock, password reset, push notifications: new features that need web + desktop parity.
5. Receipt share/print (needs a signed receipt URL like `statement-url`).

## Additional findings during implementation

- **Commission shown as a credit.** Customer history rendered every non-withdrawal as "+", so commission charges looked like deposits. Fixed: commission is a debit. Reversal/adjustment are shown unsigned until the API exposes a direction.
- **Sign-out with unsynced records.** The outbox isn't tied to a user. If an agent signs out with pending collections, they drain under the next user's token. The mobile app now warns; the backend fix is in the plan backlog.
- **Amount parsing.** `Math.round(Number(x) * 100)` was scattered across screens. Replaced by a string-based `parseAmountToMinor`, with input sanitising that treats commas as thousands separators (a `,`→`.` conversion would have turned GH₵ 1,000 into GH₵ 1.00).
