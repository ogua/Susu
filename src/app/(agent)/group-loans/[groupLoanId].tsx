import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { apiErrorMessage } from '@/api/client';
import {
  activateGroupLoan,
  getGroupLoan,
  recordGroupLoanDeposit,
  recordGroupLoanRepayment,
  writeOffGroupLoan,
} from '@/api/groupLoans';
import { InstallmentRow } from '@/components/installment-row';
import { SavingsAccountPicker } from '@/components/savings-account-picker';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Badge,
  Button,
  Card,
  confirmAction,
  EmptyState,
  ErrorState,
  Field,
  HeroCard,
  HeroStat,
  Input,
  KeyValueRow,
  LoadingState,
  Notice,
  SectionHeader,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

const PER = { daily: 'day', weekly: 'week', monthly: 'month' } as const;

/**
 * Per-member group loan: draft -> record deposit (into a chosen savings
 * account) -> activate -> repayments, with a manager-tier write-off option
 * that can optionally draw down the member's savings first. No approve/
 * reject step — the group loan feature has no maker-checker.
 */
export default function GroupLoanDetailScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { groupLoanId } = useLocalSearchParams<{ groupLoanId: string }>();
  const queryClient = useQueryClient();
  const role = useAuthStore((state) => state.user?.role);
  const isManager = role === 'branch_manager' || role === 'company_admin';

  const [amount, setAmount] = useState('');
  const [depositAccountId, setDepositAccountId] = useState<string | null>(null);
  const [writeOffReason, setWriteOffReason] = useState('');
  const [writeOffAccountId, setWriteOffAccountId] = useState<string | null>(null);
  const [writeOffAmount, setWriteOffAmount] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const busyRef = useRef(false);
  const depositKey = useIdempotencyKey();
  const repayKey = useIdempotencyKey();

  const groupLoan = useQuery({
    queryKey: ['groupLoan', groupLoanId],
    queryFn: () => getGroupLoan(groupLoanId),
    enabled: !!groupLoanId,
  });

  async function run(key: string, fn: () => Promise<unknown>, successMessage: string, onSuccess?: () => void) {
    if (busyRef.current) return;
    busyRef.current = true;
    setError(null);
    setSuccess(null);
    setBusy(key);
    try {
      await fn();
      onSuccess?.();
      setAmount('');
      setWriteOffReason('');
      setWriteOffAccountId(null);
      setWriteOffAmount('');
      setSuccess(successMessage);
      await queryClient.invalidateQueries({ queryKey: ['groupLoan', groupLoanId] });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      busyRef.current = false;
      setBusy(null);
    }
  }

  if (groupLoan.isLoading) {
    return <LoadingState label="Loading loan…" />;
  }
  if (groupLoan.isError || !groupLoan.data) {
    return <ErrorState title="Couldn't load this group loan" onRetry={() => void groupLoan.refetch()} />;
  }

  const data = groupLoan.data;
  const amountMinor = parseAmountToMinor(amount);
  const memberName = data.customer_name ?? 'the member';

  function handleDeposit() {
    if (!depositAccountId) {
      setError('Choose the savings account the deposit goes into.');
      return;
    }
    const accountId = depositAccountId;
    confirmAction({
      title: 'Record security deposit?',
      message: `${data.security_deposit_amount_formatted ? displayFormatted(data.security_deposit_amount_formatted) : ''} received from ${memberName}, credited to their savings account.`,
      confirmLabel: `Record ${displayFormatted(data.security_deposit_amount_formatted)}`,
      onConfirm: () =>
        void run(
          'deposit',
          () => recordGroupLoanDeposit(data.id, data.security_deposit_amount, accountId, depositKey.key),
          'Security deposit recorded.',
          depositKey.rotate,
        ),
    });
  }

  function handleActivate() {
    confirmAction({
      title: 'Activate and disburse?',
      message: `This disburses ${displayFormatted(data.principal_amount_formatted)} to ${memberName} and starts the repayment schedule.`,
      confirmLabel: `Disburse ${displayFormatted(data.principal_amount_formatted)}`,
      onConfirm: () => void run('activate', () => activateGroupLoan(data.id), 'Loan activated and disbursed.'),
    });
  }

  function handleRepay() {
    if (!amountMinor) {
      setError('Enter the amount being repaid.');
      return;
    }
    const minor = amountMinor;
    confirmAction({
      title: 'Record this repayment?',
      message: `${formatMoney(minor)} cash repayment from ${memberName} on loan ${data.loan_number}.`,
      confirmLabel: `Record ${formatMoney(minor)}`,
      onConfirm: () =>
        void run(
          'repay',
          () => recordGroupLoanRepayment(data.id, minor, repayKey.key),
          `Repayment of ${formatMoney(minor)} recorded.`,
          repayKey.rotate,
        ),
    });
  }

  function handleWriteOff() {
    if (!writeOffReason.trim()) {
      setError('Enter the reason for writing off this loan.');
      return;
    }
    let savingsApplied: number | undefined;
    if (writeOffAccountId) {
      const parsed = writeOffAmount.trim() ? parseAmountToMinor(writeOffAmount) : 0;
      if (parsed === null) {
        setError('Enter a valid amount to apply from savings, or leave it empty.');
        return;
      }
      savingsApplied = parsed;
    }
    const reason = writeOffReason.trim();
    const accountId = writeOffAccountId ?? undefined;
    confirmAction({
      title: 'Write off this loan?',
      message: `${memberName} owes ${displayFormatted(data.outstanding_balance_formatted)}.${
        savingsApplied ? ` ${formatMoney(savingsApplied)} will first be taken from their savings.` : ''
      } The rest is recorded as a loss. This cannot be undone.`,
      confirmLabel: 'Write off loan',
      destructive: true,
      onConfirm: () =>
        void run('writeOff', () => writeOffGroupLoan(data.id, reason, accountId, savingsApplied), 'Loan written off.'),
    });
  }

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      keyboardShouldPersistTaps="handled"
      automaticallyAdjustKeyboardInsets
      data={data.installments ?? []}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => (
        <InstallmentRow
          sequence={item.sequence}
          dueDate={item.due_date}
          status={item.status}
          amountDueFormatted={item.amount_due_formatted}
          remainingFormatted={item.remaining_formatted}
          partiallyPaid={item.remaining > 0 && item.remaining < item.amount_due}
        />
      )}
      ListHeaderComponent={
        <View style={styles.header}>
          <HeroCard
            label="Outstanding loan balance"
            amount={displayFormatted(data.outstanding_balance_formatted)}
            caption={`${memberName} · ${data.loan_number}${data.loan_group?.name ? ` · ${data.loan_group.name}` : ''}`}
          >
            <View style={styles.heroStats}>
              <HeroStat label="Loan amount" value={displayFormatted(data.principal_amount_formatted)} />
              <HeroStat label="Repaid so far" value={displayFormatted(data.amount_repaid_formatted)} />
            </View>
          </HeroCard>

          <Card>
            <KeyValueRow
              label="Repayment"
              value={`${displayFormatted(data.periodic_amount_formatted)} per ${PER[data.repayment_frequency]}`}
            />
            <KeyValueRow label="Security deposit" value={displayFormatted(data.security_deposit_amount_formatted)} />
            <View style={styles.rowBetween}>
              <ThemedText type="small" themeColor="textSecondary">
                Status
              </ThemedText>
              <View style={styles.badges}>
                <Badge label={data.status} />
                <Badge label={data.deposit_status === 'held' ? 'Deposit held' : 'Deposit pending'} tone={data.deposit_status === 'held' ? 'success' : 'warning'} />
              </View>
            </View>
          </Card>

          {error ? <Notice tone="danger" message={error} /> : null}
          {success ? <Notice tone="success" message={success} /> : null}

          {data.status === 'draft' && data.deposit_status === 'pending' ? (
            <Card style={styles.section}>
              <ThemedText type="heading">Step 1 · Record security deposit</ThemedText>
              <KeyValueRow label="Deposit due" value={displayFormatted(data.security_deposit_amount_formatted)} emphasis />
              <Field label="Credit the deposit to">
                <SavingsAccountPicker customerId={data.customer_id} value={depositAccountId} onChange={setDepositAccountId} />
              </Field>
              <Button title="Record deposit" icon="cash" loading={busy === 'deposit'} disabled={!!busy} onPress={handleDeposit} />
            </Card>
          ) : null}

          {data.status === 'draft' && data.deposit_status === 'held' ? (
            <Card style={styles.section}>
              <ThemedText type="heading">Step 2 · Activate loan</ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                Creates the repayment schedule and disburses {displayFormatted(data.principal_amount_formatted)}.
              </ThemedText>
              <Button title="Activate & disburse" icon="checkCircle" loading={busy === 'activate'} disabled={!!busy} onPress={handleActivate} />
            </Card>
          ) : null}

          {data.status === 'active' ? (
            <Card style={styles.section}>
              <ThemedText type="heading">Record repayment</ThemedText>
              <AmountInput
                size="md"
                label="Amount received"
                value={amount}
                onChangeText={(text) => {
                  setAmount(text);
                  repayKey.rotate();
                }}
                quickAmounts={data.periodic_amount > 0 ? [data.periodic_amount] : undefined}
              />
              <Button
                title={amountMinor ? `Record ${formatMoney(amountMinor)}` : 'Record repayment'}
                icon="cash"
                loading={busy === 'repay'}
                disabled={!!busy}
                onPress={handleRepay}
              />
            </Card>
          ) : null}

          {data.status === 'active' && isManager ? (
            <Card style={[styles.section, { borderColor: theme.danger }]}>
              <ThemedText type="heading" themeColor="danger">
                Write off loan
              </ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                Closes the loan permanently and records the remaining balance as a loss. This cannot be undone.
              </ThemedText>
              <Input label="Reason" value={writeOffReason} onChangeText={setWriteOffReason} multiline placeholder="Why is this loan uncollectible?" />
              <Field label="Recover from savings first" optional>
                <SavingsAccountPicker customerId={data.customer_id} value={writeOffAccountId} onChange={setWriteOffAccountId} />
              </Field>
              {writeOffAccountId ? (
                <AmountInput size="md" label="Amount to take from savings" value={writeOffAmount} onChangeText={setWriteOffAmount} />
              ) : null}
              <Button
                title="Write off loan"
                variant="destructive"
                icon="warning"
                loading={busy === 'writeOff'}
                disabled={!!busy}
                onPress={handleWriteOff}
              />
            </Card>
          ) : null}

          <SectionHeader title="Repayment schedule" />
        </View>
      }
      ListEmptyComponent={
        <EmptyState icon="calendar" title="No schedule yet" hint="The schedule is created when the loan is activated." />
      }
    />
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three, gap: Spacing.three },
  heroStats: { flexDirection: 'row', gap: Spacing.two },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 6 },
  badges: { flexDirection: 'row', gap: 6 },
  section: { gap: Spacing.three },
});
