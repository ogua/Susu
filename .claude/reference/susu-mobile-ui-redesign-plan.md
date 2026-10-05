# SusuApp Mobile — UI Redesign Plan & Status

Companion to [susu-mobile-ui-ux-audit.md](susu-mobile-ui-ux-audit.md) and the corrected brief [susu-mobile-ui-redesign-prompt.md](susu-mobile-ui-redesign-prompt.md).

QA gate after every phase: `npx tsc --noEmit` and `npm run lint`. Final gate: `npx expo export --platform android`.
Status key: ✅ done · ⏳ pending (mobile) · 🔧 needs backend · 🔁 needs web + desktop parity.

**Status (2026-10-05):** Phases 1–8 and 11 done. 9 and 10 have their core done. `tsc` is clean, `lint` has 0 errors and 0 warnings (baseline: 1 error, 4 warnings), and the Android export succeeds. **Not yet tested on a device.**

## Phase 1 — Audit ✅
See the audit doc.

## Phase 2 — Design system ✅
- `src/constants/theme.ts`: navy "trust" palette with full light/dark semantic tokens (`surface`, `surfaceMuted`, `primaryText`, `success/warning/danger/info` plus soft variants, `dangerStrong`, hero gradient, chart colours). Type scale includes `money`/`moneyLarge`/`moneyHero` with tabular figures. Spacing, radii (adds `xl`), `Shadow`/`ShadowFloating`, `MinTouch`.
- `ThemedText`: new `display`, `heading`, `bodyStrong`, `label`, `caption`, `money*` types. Old names are kept.
- `utils/money.ts`: `formatMoney` → `GH₵ 1,234.56`; `displayFormatted` normalises API `GHS …` strings; `parseAmountToMinor` avoids float drift; `sanitizeAmountInput` allows one dot, 2 dp, no negatives or leading zeros, and drops commas (thousands separators).
- `utils/format.ts`: en-GB dates, relative time, role labels, initials, greeting, date mask + strict ISO validation.

## Phase 3 — Global infrastructure ✅
- `Screen`: one safe-area + keyboard strategy (KAV offset by header height on iOS, bottom inset, `keyboardShouldPersistTaps="handled"`, sticky `footer` for the primary action).
- `OfflineBanner` (`useOnline`), `SyncStatusPill` + `useOutboxStatus` store (pending/rejected/syncing/last-synced, refreshed on enqueue and every drain).
- `SyncToast`: safe-area aware, animated, icon + text.
- `apiErrorMessage`: never surfaces 5xx bodies or transport text; maps 401/403/404/429. `apiFieldError` for inline 422 errors.
- Themed navigation (headers, contentStyle, nav theme colours) so there is no white flash in dark mode; `StatusBar` auto.
- Fixed the pre-existing lint error in `use-color-scheme.web.ts` (`useSyncExternalStore`).

## Phase 4 — Authentication ✅ login · 🔧🔁 rest
- Login: gradient brand panel, overlapping form card, icon fields, password visibility toggle, field-level `login` error from 422, banner for other errors, collapsible server settings that show the current host, trust line, scrolls on small screens, return-key flow.
- 🔧🔁 PIN/biometric unlock, password reset, OTP sign-in.

## Phase 5 — Collector experience ✅
- **Home:** greeting, role and branch; sign-out with an **unsynced-records warning**; offline banner; "Collected today" hero (server-confirmed, says it excludes records still on the phone) with collections / cash in hand / active accounts; sync pill; primary "Record a collection" button; labelled duty card; icon quick-action grid; labelled 30-day chart with total and empty state.
- **Collect (search):** 300 ms debounce, previous results kept while typing, result count, avatars, "Savings balance" label, search-aware empty states with a register CTA. Loan/statement pills moved to the collect screen.
- **Record collection:** customer card with labelled balance and agreed amount; Statement / Apply for loan; non-active account warning; Cash/MoMo segmented control; `AmountInput` with quick amounts (1×/2×/5×/10× agreed); amount shown in the button label ("Record GH₵ 5.00 cash"); MoMo phone validation and `client_reference`. **Double-submit fixed:** ref guard, and the form is replaced by the result once queued.
- **Receipt / result:** "Saved on this phone" → live-updates to "Collection recorded" when the drain confirms, or "Needs attention" with the friendly reason if rejected. Receipt shows business, customer, account, amount, method, time, collector, device reference and status. "Next customer" returns to the search.
- **Close my day:** today's stats, warning when unsynced records make expected cash wrong, display-only difference preview, confirmation naming the declared amount, locked result.
- **Sync status:** waiting / needs-attention counts, last successful sync, Sync now, human op names with amount/name summaries, friendly errors, retry. Synced location pings are hidden.
- **Register customer / Open account:** sectioned cards, segmented and chip selectors, per-field errors, auto-dashed dates with real-calendar validation, product-type descriptions, product-default hint, locked result screens.
- 🔧 Route list / expected-today / missed collections need a backend schedule concept.

## Phase 6 — Customer experience ✅
- **Home:** greeting, sign-out confirm, offline banner, "Total savings across your accounts" hero with accounts and loan balance owed, account cards with product name, "Savings balance" label and type-specific detail and progress, labelled balance chart, donut with legend.
- **Account detail:** "Savings balance" hero, round action buttons (Deposit / Withdraw / Buy shares / Statement), target / fixed-deposit / shares cards, **infinite-scroll** history using `TransactionRow` (sign + label + icon + colour, reference, method, balance-after, pending/reversed state). **Commission now shows as a debit** (was "+"). Reversal/adjustment are shown unsigned because the API doesn't give their direction.
- **Withdraw:** shows source account and balance, FD lock notice, confirmation with amount, ref guard, locked success that says the balance changes only after approval.
- **Deposit / Buy shares:** `client_reference`, phone validation, shared `MomoFields`, amount in button, PIN safety note; shares show a price / count / total breakdown.
- **Payment verify (shared):** themed, safe, large amount, honest waiting copy ("don't charge again — check again first"), OTP field with `one-time-code`, success/failure result screens.

## Phase 7 — Staff actions beyond collection ✅ / 🔁
- Loans list/detail, loan applications (shared form, guarantor for staff, eligibility notice, `client_reference`, locked result), susu groups (list/detail, per-member contribution confirm + idempotency key, payout and early-payout confirmations naming recipient and amount), group loans (list, detail with step-by-step deposit → activate → repay, issue form with schedule preview, confirmations, `client_reference`).
- Write-offs (loan and group loan) are destructive-styled with confirmations stating the outstanding amount and savings drawdown.
- Admin dashboards stay on the web.

## Phase 8 — Financial UX ✅
- `useIdempotencyKey` → `client_reference` on loan repayment, group-loan deposit/repayment, group contribution, MoMo charge (collect/deposit/shares), loan application, member-loan issue. The key is kept across retries of the same attempt and rotated on input change or success.
- `confirmAction` on every irreversible or money-moving online action, with amount-specific labels.
- Ref-based re-entrance guards on every financial submit, and results replace the form after success.

## Phase 9 — Visual polish ✅ core · ⏳ more
- Reanimated entering animations (≤ 300 ms) on dashboards, tiles, account cards, result icon and toast. Icon empty, error and loading states everywhere.
- ✅ Skeleton loaders (`Skeleton`, `SkeletonList`, `SkeletonHero`) on both dashboards and every list (accounts, transactions, loans, groups, group loans).
- ✅ Share receipt on the collection result (RN `Share`, plain text for WhatsApp/SMS/email; states "Pending confirmation" until synced). 🔁 Desktop/web equivalent: printable receipt.
- ⏳ Balance count-up animation (deliberately skipped: it delays reading a financial figure); branded illustration for login/empty states (needs a design asset).

## Phase 10 — Accessibility & performance ✅ core · ⏳ more
- Roles and states on buttons, radios (segmented/chips), progress bars, switch label, alerts/live regions. Status badges = icon + text. Combined accessibility labels on money rows. 48 dp min targets.
- Debounced search, `keepPreviousData`, infinite history, FlatList tuning.
- ⏳ Audit with TalkBack/VoiceOver and the largest font scale on a device; contrast check of the dark palette on a real screen.

## Phase 11 — QA ✅ automated · ⏳ device
`tsc` ✅ · `lint` ✅ (0/0) · `expo export --platform android` ✅. Still to do: device pass on a small Android (keyboard + gesture bar), a notched iPhone, dark mode, offline → online sync of a collection, MoMo happy and timeout paths.

## Backend / parity backlog
1. 🔧 `client_reference` on `POST /customer/withdrawal-requests` and `POST /group-loans/{id}/write-off` (mobile currently relies on UI guards only).
2. 🔧 `/sync/batch` → return `reference` + `balance_after` for `collection.record` so receipts can show the server reference and new balance.
3. 🔧 Transaction resource: add a `direction` (credit/debit) so reversals/adjustments can be signed correctly.
4. 🔧 Outbox ops should carry the recording user. Today an unsynced op drains under whoever signs in next (mobile now warns on sign-out).
5. 🔧🔁 Schedules/routes, missed-collection tracking, notifications, PIN unlock, password reset.
6. 🔧🔁 Shareable receipt URL (like `statement-url`) for WhatsApp/SMS/print.
7. 🔁 Parity: this pass is visual/UX only and adds no new user-facing features, so web and desktop need no feature work. The **safety fixes** (confirmations on write-off and payout, double-submit guards) are worth mirroring in Filament and JavaFX.
