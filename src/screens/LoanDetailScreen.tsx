import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { apiErrorMessage } from '@/api/client';
import { getLoan, recordLoanRepayment } from '@/api/loans';
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
  LoadingState,
  Notice,
  SectionHeader,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { drainOutbox } from '@/sync/engine';
import { enqueueLoanWriteOff } from '@/sync/ops';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

/**
 * Shared by the agent and customer loan-detail routes — only the "Record
 * Repayment" and "Write Off" sections differ (staff/manager-tier, mirrors
 * the API's role gating), decided here by the signed-in user's role rather
 * than two near-duplicate screens. Write-off has no direct HTTP route on the
 * backend (sync-batch only), so it goes through the offline outbox even
 * when online — the drain happens immediately after queueing.
 */
export default function LoanDetailScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { loanId } = useLocalSearchParams<{ loanId: string }>();
  const role = useAuthStore((state) => state.user?.role);
  const isStaff = role === 'field_agent' || role === 'branch_manager' || role === 'company_admin';
  const isManager = role === 'branch_manager' || role === 'company_admin';
  const queryClient = useQueryClient();

  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [repaid, setRepaid] = useState<string | null>(null);
  const repayKey = useIdempotencyKey();
  const busyRef = useRef(false);

  const [writeOffReason, setWriteOffReason] = useState('');
  const [writeOffAccountId, setWriteOffAccountId] = useState<string | null>(null);
  const [writeOffAmount, setWriteOffAmount] = useState('');
  const [writeOffSubmitting, setWriteOffSubmitting] = useState(false);
  const [writeOffError, setWriteOffError] = useState<string | null>(null);
  const [writeOffSaved, setWriteOffSaved] = useState(false);

  const loan = useQuery({
    queryKey: ['loan', loanId],
    queryFn: () => getLoan(loanId),
    enabled: !!loanId,
  });

  async function recordRepayment(minor: number) {
    if (busyRef.current) return;
    busyRef.current = true;
    setSubmitting(true);
    try {
      await recordLoanRepayment(loanId, minor, repayKey.key);
      repayKey.rotate();
      setAmount('');
      setRepaid(`Repayment of ${formatMoney(minor)} recorded.`);
      await queryClient.invalidateQueries({ queryKey: ['loan', loanId] });
    } catch (err) {
      // Keep the same key: retrying this exact repayment can't double-post.
      setError(apiErrorMessage(err));
    } finally {
      busyRef.current = false;
      setSubmitting(false);
    }
  }

  function handleRecordRepayment() {
    setError(null);
    setRepaid(null);
    const minor = parseAmountToMinor(amount);
    if (!minor) {
      setError('Enter the amount the customer is repaying.');
      return;
    }
    confirmAction({
      title: 'Record this repayment?',
      message: `${formatMoney(minor)} cash repayment on loan ${loan.data?.loan_number ?? ''}.`,
      confirmLabel: `Record ${formatMoney(minor)}`,
      onConfirm: () => void recordRepayment(minor),
    });
  }

  async function writeOff(savingsApplied: number | undefined) {
    if (busyRef.current || writeOffSaved) return;
    busyRef.current = true;
    setWriteOffSubmitting(true);
    try {
      await enqueueLoanWriteOff({
        loan_id: loanId,
        reason: writeOffReason.trim(),
        savings_account_id: writeOffAccountId ?? undefined,
        savings_amount_applied: savingsApplied,
      });
      setWriteOffSaved(true);
      void drainOutbox().then(() => queryClient.invalidateQueries({ queryKey: ['loan', loanId] }));
    } catch {
      setWriteOffError("The write-off couldn't be saved on the phone. Nothing was recorded — please try again.");
    } finally {
      busyRef.current = false;
      setWriteOffSubmitting(false);
    }
  }

  function handleWriteOff() {
    setWriteOffError(null);
    if (!writeOffReason.trim()) {
      setWriteOffError('Enter the reason for writing off this loan.');
      return;
    }
    let savingsApplied: number | undefined;
    if (writeOffAccountId) {
      const parsed = writeOffAmount.trim() ? parseAmountToMinor(writeOffAmount) : 0;
      if (parsed === null) {
        setWriteOffError('Enter a valid amount to apply from savings, or leave it empty.');
        return;
      }
      savingsApplied = parsed;
    }
    const data = loan.data;
    confirmAction({
      title: 'Write off this loan?',
      message: `Loan ${data?.loan_number ?? ''} has ${displayFormatted(data?.outstanding_balance_formatted)} outstanding.${
        savingsApplied ? ` ${formatMoney(savingsApplied)} will first be taken from the customer's savings.` : ''
      } The rest is recorded as a loss. This cannot be undone.`,
      confirmLabel: 'Write off loan',
      destructive: true,
      onConfirm: () => void writeOff(savingsApplied),
    });
  }

  if (loan.isLoading) {
    return <LoadingState label="Loading loan…" />;
  }
  if (loan.isError || !loan.data) {
    return <ErrorState title="Couldn't load this loan" onRetry={() => void loan.refetch()} />;
  }

  const data = loan.data;
  const installments = data.installments ?? [];

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      keyboardShouldPersistTaps="handled"
      automaticallyAdjustKeyboardInsets
      data={installments}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => (
        <InstallmentRow
          sequence={item.sequence}
          dueDate={item.due_date}
          status={item.status}
          amountDueFormatted={item.total_due_formatted}
          remainingFormatted={formatMoney(item.remaining)}
          partiallyPaid={item.remaining > 0 && item.remaining < item.total_due}
        />
      )}
      ListHeaderComponent={
        <View style={styles.header}>
          <HeroCard
            label="Outstanding loan balance"
            amount={displayFormatted(data.outstanding_balance_formatted)}
            caption={`${data.loan_number}${data.loan_product?.name ? ` · ${data.loan_product.name}` : ''}`}
          >
            <View style={styles.heroStats}>
              <HeroStat label="Amount borrowed" value={displayFormatted(data.principal_amount_formatted)} />
              <HeroStat label="Total to repay" value={displayFormatted(data.total_repayable_formatted)} />
            </View>
          </HeroCard>

          <View style={styles.statusRow}>
            <ThemedText type="small" themeColor="textSecondary">
              Loan status
            </ThemedText>
            <Badge label={data.status} />
          </View>

          {data.rejection_reason ? <Notice tone="danger" title="Application rejected" message={data.rejection_reason} /> : null}
          {data.write_off_reason ? <Notice tone="warning" title="Written off" message={data.write_off_reason} /> : null}

          {isStaff && data.status === 'disbursed' ? (
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
                error={error}
              />
              {repaid ? <Notice tone="success" message={repaid} /> : null}
              <Button
                title={parseAmountToMinor(amount) ? `Record ${formatMoney(parseAmountToMinor(amount) ?? 0)}` : 'Record repayment'}
                icon="cash"
                loading={submitting}
                loadingTitle="Recording…"
                onPress={handleRecordRepayment}
              />
            </Card>
          ) : null}

          {isManager && data.status === 'disbursed' ? (
            <Card style={[styles.section, { borderColor: theme.danger }]}>
              <ThemedText type="heading" themeColor="danger">
                Write off loan
              </ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                Closes the loan permanently and records the remaining balance as a loss. This cannot be undone.
              </ThemedText>
              {writeOffSaved ? (
                <Notice
                  tone="success"
                  message="Write-off saved and sent for processing. The loan status updates once the server confirms it."
                />
              ) : (
                <>
                  <Input label="Reason" value={writeOffReason} onChangeText={setWriteOffReason} multiline placeholder="Why is this loan uncollectible?" />
                  <Field label="Recover from savings first" optional>
                    <SavingsAccountPicker customerId={data.customer_id} value={writeOffAccountId} onChange={setWriteOffAccountId} />
                  </Field>
                  {writeOffAccountId ? (
                    <AmountInput size="md" label="Amount to take from savings" value={writeOffAmount} onChangeText={setWriteOffAmount} />
                  ) : null}
                  {writeOffError ? <Notice tone="danger" message={writeOffError} /> : null}
                  <Button
                    title="Write off loan"
                    variant="destructive"
                    icon="warning"
                    loading={writeOffSubmitting}
                    onPress={handleWriteOff}
                  />
                </>
              )}
            </Card>
          ) : null}

          <SectionHeader title={`Repayment schedule${installments.length ? ` · ${installments.length} instalments` : ''}`} />
        </View>
      }
      ListEmptyComponent={
        <EmptyState icon="calendar" title="No schedule yet" hint="The repayment schedule is created when the loan is disbursed." />
      }
    />
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three, gap: Spacing.three },
  heroStats: { flexDirection: 'row', gap: Spacing.two },
  statusRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  section: { gap: Spacing.three },
});
