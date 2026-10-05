import { useQuery } from '@tanstack/react-query';
import { useMemo, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { getCollectionSheet } from '@/api/collectionSheet';
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
  Notice,
  ResultView,
  Screen,
  SectionHeader,
  SkeletonList,
  type Option,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueCollection, enqueueGroupLoanRepayment, enqueueLoanRepayment } from '@/sync/ops';
import type { CollectionSheetRow } from '@/types/api';
import { formatMoney, minorToInput, parseAmountToMinor } from '@/utils/money';

type Entry = { repayment: string; deposit: string };

const MY_SHEET = 'mine';

/**
 * "Enter Transaction" — the day's collection sheet. Pick a customer group (or
 * "My sheet" for everything due to me today); each row is pre-filled with
 * what the member owes, plus an optional savings deposit. Submitting queues
 * one outbox op per amount, so a sheet can be worked without signal and
 * syncs later — each op is idempotent on its op_id.
 */
export default function CollectionSheetScreen() {
  const [groupId, setGroupId] = useState<string>(MY_SHEET);
  // Only what the agent typed; untouched rows fall back to what's due.
  const [edits, setEdits] = useState<Record<string, Partial<Entry>>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [posted, setPosted] = useState<{ repayments: number; deposits: number; count: number } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);

  const groups = useQuery({ queryKey: ['agent', 'loanGroups'], queryFn: () => getLoanGroups() });
  const sheet = useQuery({
    queryKey: ['agent', 'collectionSheet', groupId],
    queryFn: () => getCollectionSheet(groupId === MY_SHEET ? {} : { loan_group_id: groupId }),
  });

  function entryFor(row: CollectionSheetRow): Entry {
    return {
      repayment: edits[row.key]?.repayment ?? (row.loan_id && row.amount_due > 0 ? minorToInput(row.amount_due) : ''),
      deposit: edits[row.key]?.deposit ?? '',
    };
  }

  function changeGroup(value: string) {
    setGroupId(value);
    setEdits({});
    setErrors({});
  }

  const groupOptions: Option<string>[] = useMemo(
    () => [
      { value: MY_SHEET, label: 'My sheet' },
      ...(groups.data?.data ?? []).filter((group) => group.is_active).map((group) => ({ value: group.id, label: group.name })),
    ],
    [groups.data],
  );

  const rows = sheet.data ?? [];
  let totalRepayments = 0;
  let totalDeposits = 0;
  for (const row of rows) {
    const entry = entryFor(row);
    totalRepayments += parseAmountToMinor(entry.repayment) ?? 0;
    totalDeposits += parseAmountToMinor(entry.deposit) ?? 0;
  }
  const totals = { repayments: totalRepayments, deposits: totalDeposits };

  function update(key: string, field: keyof Entry, value: string) {
    setEdits((current) => ({ ...current, [key]: { ...current[key], [field]: value } }));
  }

  /** Mirror the server's rules up front so a queued op can't be rejected later. */
  function validate(rows: CollectionSheetRow[]): boolean {
    const found: Record<string, string> = {};
    for (const row of rows) {
      const entry = entryFor(row);
      const repayment = parseAmountToMinor(entry.repayment) ?? 0;
      const deposit = parseAmountToMinor(entry.deposit) ?? 0;

      if (repayment > 0 && repayment > row.outstanding) {
        found[row.key] = `Repayment is more than the ${formatMoney(row.outstanding)} balance.`;
      } else if (deposit > 0 && row.contribution_amount && deposit % row.contribution_amount !== 0) {
        found[row.key] = `Deposit must be a multiple of ${formatMoney(row.contribution_amount)}.`;
      }
    }
    setErrors(found);

    return Object.keys(found).length === 0;
  }

  async function post(rows: CollectionSheetRow[]) {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setSubmitting(true);

    try {
      let count = 0;
      for (const row of rows) {
        const entry = entryFor(row);
        const repayment = parseAmountToMinor(entry.repayment) ?? 0;
        const deposit = parseAmountToMinor(entry.deposit) ?? 0;

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
      setEdits({});
      void drainOutbox().then(() => void sheet.refetch());
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    if (totals.repayments + totals.deposits <= 0) {
      setErrors({ _: 'Enter at least one amount.' });
      return;
    }
    if (!validate(rows)) return;

    confirmAction({
      title: 'Post collection sheet?',
      message: `${formatMoney(totals.repayments)} in repayments and ${formatMoney(totals.deposits)} in deposits.`,
      confirmLabel: 'Post',
      onConfirm: () => void post(rows),
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

  return (
    <Screen
      footer={
        rows.length > 0 ? (
          <View style={styles.footer}>
            <View style={styles.totals}>
              <ThemedText type="caption" themeColor="textMuted">
                Repayments {formatMoney(totals.repayments)} · Deposits {formatMoney(totals.deposits)}
              </ThemedText>
            </View>
            <Button title="Submit sheet" icon="checkCircle" loading={submitting} onPress={handleSubmit} />
          </View>
        ) : undefined
      }
    >
      <SectionHeader title="Group" />
      <ChipSelect options={groupOptions} value={groupId} onChange={changeGroup} accessibilityLabel="Customer group" />

      {errors._ ? <Notice tone="danger" message={errors._} /> : null}

      <SectionHeader title={`Due today${rows.length ? ` (${rows.length})` : ''}`} />
      {sheet.isLoading ? (
        <SkeletonList />
      ) : sheet.isError ? (
        <ErrorState title="Couldn't load the sheet" hint="The sheet needs a connection to load. Queued entries still sync later." onRetry={() => void sheet.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState icon="checkCircle" title="Nobody is due" hint="Pick a group to see all its members, or check back tomorrow." />
      ) : (
        rows.map((row) => (
          <Card key={row.key} style={styles.card}>
            <View style={styles.rowHeader}>
              <View style={styles.flex}>
                <ThemedText type="subtitle">{row.customer_name}</ThemedText>
                <ThemedText type="caption" themeColor="textMuted">
                  {[row.loan_number, row.product, row.phone].filter(Boolean).join(' · ') || 'No active loan'}
                </ThemedText>
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
                value={entryFor(row).repayment}
                onChangeText={(value) => update(row.key, 'repayment', value)}
                hint={`Balance ${formatMoney(row.outstanding)}`}
              />
            ) : null}
            {row.savings_account_id ? (
              <AmountInput
                label={`Savings deposit · ${row.savings_account_number}`}
                size="md"
                value={entryFor(row).deposit}
                onChangeText={(value) => update(row.key, 'deposit', value)}
                hint={`Balance ${formatMoney(row.savings_balance ?? 0)}`}
                quickAmounts={row.contribution_amount ? [row.contribution_amount, row.contribution_amount * 2, row.contribution_amount * 5] : undefined}
              />
            ) : null}
            {errors[row.key] ? <Notice tone="danger" message={errors[row.key]} /> : null}
          </Card>
        ))
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { gap: Spacing.two, marginBottom: Spacing.two },
  rowHeader: { flexDirection: 'row', alignItems: 'flex-start', gap: Spacing.two },
  flex: { flex: 1 },
  right: { alignItems: 'flex-end' },
  footer: { gap: Spacing.two },
  totals: { alignItems: 'center' },
});
