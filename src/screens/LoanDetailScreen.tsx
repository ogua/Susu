import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { getLoan, recordLoanRepayment } from '@/api/loans';
import { SavingsAccountPicker } from '@/components/savings-account-picker';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';
import { drainOutbox } from '@/sync/engine';
import { enqueueLoanWriteOff } from '@/sync/ops';
import type { LoanInstallment } from '@/types/api';
import { Palette } from '@/constants/theme';

/**
 * Shared by the agent and customer loan-detail routes — only the "Record
 * Repayment" and "Write Off" sections differ (staff/manager-tier, mirrors
 * the API's role gating), decided here by the signed-in user's role rather
 * than two near-duplicate screens. Write-off has no direct HTTP route on the
 * backend (sync-batch only), so it goes through the offline outbox even
 * when online — the drain happens immediately after queueing.
 */
export default function LoanDetailScreen() {
  const { loanId } = useLocalSearchParams<{ loanId: string }>();
  const role = useAuthStore((state) => state.user?.role);
  const isStaff = role === 'field_agent' || role === 'branch_manager' || role === 'company_admin';
  const isManager = role === 'branch_manager' || role === 'company_admin';
  const queryClient = useQueryClient();

  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [writeOffReason, setWriteOffReason] = useState('');
  const [writeOffAccountId, setWriteOffAccountId] = useState<string | null>(null);
  const [writeOffAmount, setWriteOffAmount] = useState('');
  const [writeOffSubmitting, setWriteOffSubmitting] = useState(false);
  const [writeOffError, setWriteOffError] = useState<string | null>(null);
  const [writeOffSaved, setWriteOffSaved] = useState<string | null>(null);

  const loan = useQuery({
    queryKey: ['loan', loanId],
    queryFn: () => getLoan(loanId),
    enabled: !!loanId,
  });

  async function handleRecordRepayment() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setSubmitting(true);
    try {
      await recordLoanRepayment(loanId, Math.round(parsed * 100));
      setAmount('');
      await queryClient.invalidateQueries({ queryKey: ['loan', loanId] });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleWriteOff() {
    setWriteOffError(null);
    if (!writeOffReason.trim()) {
      setWriteOffError('Enter a reason for the write-off.');
      return;
    }

    setWriteOffSubmitting(true);
    try {
      await enqueueLoanWriteOff({
        loan_id: loanId,
        reason: writeOffReason.trim(),
        savings_account_id: writeOffAccountId ?? undefined,
        savings_amount_applied: writeOffAccountId ? Math.round(Number(writeOffAmount || '0') * 100) : undefined,
      });

      setWriteOffReason('');
      setWriteOffAccountId(null);
      setWriteOffAmount('');
      setWriteOffSaved('Write-off saved. It will sync automatically.');
      // Fire-and-forget: don't block on network: this is an offline-capable op.
      void drainOutbox().then(() => queryClient.invalidateQueries({ queryKey: ['loan', loanId] }));
    } catch {
      setWriteOffError('Could not save the write-off locally. Please try again.');
    } finally {
      setWriteOffSubmitting(false);
    }
  }

  function renderInstallment({ item }: { item: LoanInstallment }) {
    return (
      <View style={styles.installmentRow}>
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">
            #{item.sequence} — {new Date(item.due_date).toLocaleDateString()}
          </ThemedText>
          <ThemedText type="small">{item.status.replaceAll('_', ' ')}</ThemedText>
        </View>
        <View style={{ alignItems: 'flex-end' }}>
          <ThemedText>{item.total_due_formatted}</ThemedText>
          {item.remaining > 0 && item.remaining < item.total_due ? (
            <ThemedText type="small">Remaining: GHS {(item.remaining / 100).toFixed(2)}</ThemedText>
          ) : null}
        </View>
      </View>
    );
  }

  if (loan.isLoading) {
    return <ActivityIndicator style={{ marginTop: 24 }} />;
  }
  if (loan.isError || !loan.data) {
    return <ThemedText style={styles.empty}>Could not load this loan.</ThemedText>;
  }

  const data = loan.data;

  return (
    <FlatList
      contentContainerStyle={styles.container}
      data={data.installments ?? []}
      keyExtractor={(item) => item.id}
      renderItem={renderInstallment}
      ItemSeparatorComponent={() => <View style={styles.separator} />}
      ListHeaderComponent={
        <View style={{ gap: 12, marginBottom: 12 }}>
          <View style={styles.card}>
            <ThemedText type="subtitle">{data.loan_number}</ThemedText>
            <ThemedText type="small">{data.loan_product?.name}</ThemedText>
            <ThemedText>Principal: {data.principal_amount_formatted}</ThemedText>
            <ThemedText>Total repayable: {data.total_repayable_formatted}</ThemedText>
            <ThemedText>Outstanding: {data.outstanding_balance_formatted}</ThemedText>
            <ThemedText type="small">Status: {data.status.replaceAll('_', ' ')}</ThemedText>
            {data.rejection_reason ? (
              <ThemedText type="small" style={styles.error}>
                Rejected: {data.rejection_reason}
              </ThemedText>
            ) : null}
          </View>

          {isStaff && data.status === 'disbursed' ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Record Repayment</ThemedText>
              <TextInput
                style={styles.input}
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={setAmount}
                placeholder="Amount (GHS)"
              />
              {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}
              <Pressable
                style={[styles.button, submitting && styles.buttonDisabled]}
                onPress={handleRecordRepayment}
                disabled={submitting}
              >
                {submitting ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <ThemedText style={styles.buttonText}>Record Repayment</ThemedText>
                )}
              </Pressable>
            </View>
          ) : null}

          {isManager && data.status === 'disbursed' ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Write Off Loan</ThemedText>
              <ThemedText type="small">
                Permanently closes the loan and recognizes the remaining balance as a loss. This cannot be undone.
              </ThemedText>
              <TextInput
                style={styles.input}
                value={writeOffReason}
                onChangeText={setWriteOffReason}
                placeholder="Reason"
                multiline
              />
              <ThemedText type="small">Apply savings first (optional):</ThemedText>
              <SavingsAccountPicker
                customerId={data.customer_id}
                value={writeOffAccountId}
                onChange={setWriteOffAccountId}
              />
              {writeOffAccountId ? (
                <TextInput
                  style={styles.input}
                  keyboardType="decimal-pad"
                  value={writeOffAmount}
                  onChangeText={setWriteOffAmount}
                  placeholder="Amount to apply (GHS)"
                />
              ) : null}
              {writeOffError ? <ThemedText style={styles.error}>{writeOffError}</ThemedText> : null}
              {writeOffSaved ? <ThemedText style={{ color: Palette.success }}>{writeOffSaved}</ThemedText> : null}
              <Pressable
                style={[styles.buttonDanger, writeOffSubmitting && styles.buttonDisabled]}
                onPress={handleWriteOff}
                disabled={writeOffSubmitting}
              >
                {writeOffSubmitting ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <ThemedText style={styles.buttonText}>Write Off</ThemedText>
                )}
              </Pressable>
            </View>
          ) : null}

          <ThemedText type="subtitle">Repayment Schedule</ThemedText>
        </View>
      }
      ListEmptyComponent={<ThemedText style={styles.empty}>No schedule yet.</ThemedText>}
    />
  );
}

const styles = StyleSheet.create({
  container: { padding: 16 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Palette.border,
    padding: 16,
    gap: 6,
    backgroundColor: '#ffffff',
  },
  installmentRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 10 },
  separator: { height: 1, backgroundColor: Palette.border },
  input: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  button: {
    backgroundColor: Palette.primary500,
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonDanger: {
    backgroundColor: Palette.danger,
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700' },
  error: { color: Palette.danger },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
