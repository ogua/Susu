import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { getCollectionSheet, type CollectionSheetParams } from '@/api/collectionSheet';
import { getLoanGroups } from '@/api/loanGroups';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Button,
  Card,
  ChipSelect,
  confirmAction,
  EmptyState,
  ErrorState,
  Input,
  Notice,
  ResultView,
  Screen,
  SectionHeader,
  SkeletonList,
  type Option,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueCollection, enqueueGroupLoanRepayment, enqueueLoanRepayment } from '@/sync/ops';
import type { CollectionSheetRow } from '@/types/api';
import { formatMoney, parseAmountToMinor } from '@/utils/money';

/** What the agent typed for one row, kept with the row so it survives a search or group change. */
type Entry = { row: CollectionSheetRow; repayment: string; deposit: string };

const DUE_TODAY = 'due';
const ALL_CUSTOMERS = 'all';
const SEARCH_DEBOUNCE_MS = 300;

/**
 * "Enter Transaction" — the day's collection sheet. Show who is due today, all
 * my customers (savers and loans not yet due too), or one group; search by
 * name, phone, code, loan/account number or group. Each row shows what the
 * member owes (one tap fills it in) plus an optional savings deposit. Rows
 * start empty so only customers who actually paid are posted.
 *
 * Entries are kept across searches and group changes and all of them are
 * posted together, so hiding a row never drops an amount the agent typed.
 * Submitting queues one outbox op per amount, so a sheet can be worked
 * without signal and syncs later — each op is idempotent on its op_id.
 */
export default function CollectionSheetScreen() {
  const theme = useTheme();
  // Opened from a group page → start on that group.
  const params = useLocalSearchParams<{ groupId?: string }>();
  const [view, setView] = useState<string>(params.groupId ?? DUE_TODAY);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [entries, setEntries] = useState<Record<string, Entry>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [posted, setPosted] = useState<{ repayments: number; deposits: number; count: number } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [search]);

  const query: CollectionSheetParams = {
    ...(view === ALL_CUSTOMERS ? { all_customers: true } : view === DUE_TODAY ? {} : { loan_group_id: view }),
    ...(debounced ? { search: debounced } : {}),
  };

  const groups = useQuery({ queryKey: ['agent', 'loanGroups'], queryFn: () => getLoanGroups() });
  const sheet = useQuery({
    queryKey: ['agent', 'collectionSheet', query],
    queryFn: () => getCollectionSheet(query),
    placeholderData: keepPreviousData,
  });

  const viewOptions: Option<string>[] = useMemo(
    () => [
      { value: DUE_TODAY, label: 'Due today' },
      { value: ALL_CUSTOMERS, label: 'All my customers' },
      ...(groups.data?.data ?? []).filter((group) => group.is_active).map((group) => ({ value: group.id, label: group.name })),
    ],
    [groups.data],
  );

  const rows = sheet.data?.rows ?? [];
  const searching = search.trim() !== debounced || (sheet.isFetching && !sheet.isRefetching);
  const capped = view === ALL_CUSTOMERS && sheet.data !== undefined && new Set(rows.map((row) => row.customer_id)).size >= sheet.data.customerLimit;

  function amountsOf(entry: Entry) {
    return { repayment: parseAmountToMinor(entry.repayment) ?? 0, deposit: parseAmountToMinor(entry.deposit) ?? 0 };
  }

  // Totals cover every entry, including rows the current search hides.
  const filled = Object.values(entries).filter((entry) => {
    const { repayment, deposit } = amountsOf(entry);
    return repayment > 0 || deposit > 0;
  });
  const totals = filled.reduce(
    (sum, entry) => {
      const { repayment, deposit } = amountsOf(entry);
      return { repayments: sum.repayments + repayment, deposits: sum.deposits + deposit };
    },
    { repayments: 0, deposits: 0 },
  );
  const payers = new Set(filled.map((entry) => entry.row.customer_id)).size;
  const visibleKeys = new Set(rows.map((row) => row.key));
  const hiddenEntries = filled.filter((entry) => !visibleKeys.has(entry.row.key)).length;

  function update(row: CollectionSheetRow, field: 'repayment' | 'deposit', value: string) {
    setEntries((current) => {
      const existing = current[row.key] ?? { row, repayment: '', deposit: '' };
      return { ...current, [row.key]: { ...existing, row, [field]: value } };
    });
    setErrors(({ [row.key]: _cleared, ...rest }) => rest);
  }

  /** Mirror the server's rules up front so a queued op can't be rejected later. */
  function validate(list: Entry[]): boolean {
    const found: Record<string, string> = {};
    for (const entry of list) {
      const { row } = entry;
      const { repayment, deposit } = amountsOf(entry);

      if (repayment > 0 && repayment > row.outstanding) {
        found[row.key] = `${row.customer_name}: repayment is more than the ${formatMoney(row.outstanding)} balance.`;
      } else if (deposit > 0 && row.contribution_amount && deposit % row.contribution_amount !== 0) {
        found[row.key] = `${row.customer_name}: deposit must be a multiple of ${formatMoney(row.contribution_amount)}.`;
      }
    }
    setErrors(found);

    return Object.keys(found).length === 0;
  }

  async function post(list: Entry[]) {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setSubmitting(true);

    try {
      let count = 0;
      for (const entry of list) {
        const { row } = entry;
        const { repayment, deposit } = amountsOf(entry);

        if (repayment > 0 && row.loan_id) {
          if (row.loan_type === 'group') {
            await enqueueGroupLoanRepayment({ group_loan_id: row.loan_id, amount: repayment });
          } else {
            await enqueueLoanRepayment({ loan_id: row.loan_id, amount: repayment });
          }
          count++;
        }
        if (deposit > 0 && row.savings_account_id) {
          await enqueueCollection({ savings_account_id: row.savings_account_id, amount: deposit });
          count++;
        }
      }

      setPosted({ ...totals, count });
      setEntries({});
      void drainOutbox().then(() => void sheet.refetch());
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    if (filled.length === 0) {
      setErrors({ _: 'Enter at least one amount.' });
      return;
    }
    if (!validate(filled)) return;

    const hiddenNote = hiddenEntries > 0 ? ` This includes ${hiddenEntries} entr${hiddenEntries === 1 ? 'y' : 'ies'} not shown by the current filter.` : '';
    confirmAction({
      title: 'Post collection sheet?',
      message: `${formatMoney(totals.repayments)} in repayments and ${formatMoney(totals.deposits)} in deposits from ${payers} customer(s).${hiddenNote} Only post money you have actually collected.`,
      confirmLabel: 'Post',
      onConfirm: () => void post(filled),
    });
  }

  if (posted) {
    return (
      <Screen>
        <ResultView
          tone="pending"
          title="Sheet queued"
          amount={formatMoney(posted.repayments + posted.deposits)}
          message={`${posted.count} transaction(s) saved on this phone. They sync automatically when you're online.`}
        >
          <Button title="Back to the sheet" variant="secondary" onPress={() => setPosted(null)} />
        </ResultView>
      </Screen>
    );
  }

  const errorList = Object.entries(errors).filter(([key]) => key === '_' || !visibleKeys.has(key));

  return (
    <Screen
      footer={
        filled.length > 0 || rows.length > 0 ? (
          <View style={styles.footer}>
            <View style={styles.totals}>
              <ThemedText type="caption" themeColor="textMuted">
                {payers} paying · Repayments {formatMoney(totals.repayments)} · Deposits {formatMoney(totals.deposits)}
              </ThemedText>
              {hiddenEntries > 0 ? (
                <ThemedText type="caption" themeColor="warning">
                  {hiddenEntries} entr{hiddenEntries === 1 ? 'y is' : 'ies are'} hidden by the filter and will also be posted.
                </ThemedText>
              ) : null}
            </View>
            <Button title="Submit sheet" icon="checkCircle" loading={submitting} onPress={handleSubmit} />
          </View>
        ) : undefined
      }
    >
      <SectionHeader title="Show" />
      <ChipSelect options={viewOptions} value={view} onChange={setView} accessibilityLabel="Which customers to show" />

      <View style={styles.searchRow}>
        <View style={styles.flex}>
          <Input
            icon="search"
            placeholder="Name, phone, code, account or group"
            value={search}
            onChangeText={setSearch}
            autoCapitalize="none"
            autoCorrect={false}
            returnKeyType="search"
            clearButtonMode="while-editing"
            accessibilityLabel="Search the sheet"
          />
        </View>
        {searching ? <ActivityIndicator size="small" color={theme.primary} /> : null}
      </View>

      {errorList.map(([key, message]) => (
        <Notice key={key} tone="danger" message={message} />
      ))}

      <SectionHeader title={`${view === ALL_CUSTOMERS ? 'Customers' : view === DUE_TODAY ? 'Due today' : 'Members'}${rows.length ? ` (${rows.length})` : ''}`} />
      {capped ? <Notice tone="info" message={`Showing the first ${sheet.data?.customerLimit} customers. Search to find someone else.`} /> : null}
      {sheet.isLoading ? (
        <SkeletonList />
      ) : sheet.isError ? (
        <ErrorState title="Couldn't load the sheet" hint="The sheet needs a connection to load. Queued entries still sync later." onRetry={() => void sheet.refetch()} />
      ) : rows.length === 0 ? (
        debounced ? (
          <EmptyState
            icon="search"
            title="No match"
            hint={view === ALL_CUSTOMERS ? 'Check the spelling, or search by phone or account number.' : 'Not found here. Try "All my customers".'}
            actionLabel={view === ALL_CUSTOMERS ? undefined : 'Search all my customers'}
            onAction={view === ALL_CUSTOMERS ? undefined : () => setView(ALL_CUSTOMERS)}
          />
        ) : (
          <EmptyState
            icon="checkCircle"
            title="Nobody is due"
            hint='Pick "All my customers" or a group to take a payment from someone not due today.'
          />
        )
      ) : (
        rows.map((row) => {
          const entry = entries[row.key];

          return (
            <Card key={row.key} style={styles.card}>
              <View style={styles.rowHeader}>
                <View style={styles.flex}>
                  <ThemedText type="subtitle">{row.customer_name}</ThemedText>
                  <ThemedText type="caption" themeColor="textMuted">
                    {[row.loan_number, row.product, row.phone].filter(Boolean).join(' · ') || 'No active loan'}
                  </ThemedText>
                  {row.group_name ? (
                    <ThemedText type="caption" themeColor="textSecondary">
                      Group: {row.group_name}
                    </ThemedText>
                  ) : null}
                </View>
                {row.loan_id ? (
                  <View style={styles.right}>
                    <ThemedText type="caption" themeColor="textMuted">
                      Due {formatMoney(row.amount_due)}
                    </ThemedText>
                    {row.overdue > 0 ? (
                      <ThemedText type="caption" themeColor="danger">
                        Overdue {formatMoney(row.overdue)}
                      </ThemedText>
                    ) : null}
                  </View>
                ) : null}
              </View>

              {row.loan_id ? (
                <AmountInput
                  label="Loan repayment"
                  size="md"
                  value={entry?.repayment ?? ''}
                  onChangeText={(value) => update(row, 'repayment', value)}
                  hint={`Balance ${formatMoney(row.outstanding)}${row.amount_due > 0 ? ' · tap the amount due if paid in full' : ''}`}
                  quickAmounts={row.amount_due > 0 ? [row.amount_due] : undefined}
                />
              ) : null}
              {row.savings_account_id ? (
                <AmountInput
                  label={`Savings deposit · ${row.savings_account_number}`}
                  size="md"
                  value={entry?.deposit ?? ''}
                  onChangeText={(value) => update(row, 'deposit', value)}
                  hint={`Balance ${formatMoney(row.savings_balance ?? 0)}`}
                  quickAmounts={row.contribution_amount ? [row.contribution_amount, row.contribution_amount * 2, row.contribution_amount * 5] : undefined}
                />
              ) : null}
              {!row.loan_id && !row.savings_account_id ? (
                <ThemedText type="caption" themeColor="textMuted">
                  No loan or savings account you can collect for.
                </ThemedText>
              ) : null}
              {errors[row.key] ? <Notice tone="danger" message={errors[row.key]} /> : null}
            </Card>
          );
        })
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { gap: Spacing.two, marginBottom: Spacing.two },
  rowHeader: { flexDirection: 'row', alignItems: 'flex-start', gap: Spacing.two },
  searchRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two, marginTop: Spacing.two },
  flex: { flex: 1 },
  right: { alignItems: 'flex-end' },
  footer: { gap: Spacing.two },
  totals: { alignItems: 'center', gap: 2 },
});
