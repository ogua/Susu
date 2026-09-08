import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import {
  activateGroupLoan,
  applyGroupLoanDeposit,
  getGroupLoan,
  recordGroupLoanDeposit,
  recordGroupLoanRepayment,
} from '@/api/groupLoans';
import { ThemedText } from '@/components/themed-text';
import type { GroupLoanInstallment } from '@/types/api';
import { Palette } from '@/constants/theme';

/**
 * Per-member group loan: draft -> record deposit -> activate -> repayments,
 * with an "apply deposit to balance" option. No approve/reject step — the
 * group loan feature has no maker-checker.
 */
export default function GroupLoanDetailScreen() {
  const { groupLoanId } = useLocalSearchParams<{ groupLoanId: string }>();
  const queryClient = useQueryClient();

  const [amount, setAmount] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const groupLoan = useQuery({
    queryKey: ['groupLoan', groupLoanId],
    queryFn: () => getGroupLoan(groupLoanId),
    enabled: !!groupLoanId,
  });

  async function run(key: string, fn: () => Promise<unknown>) {
    setError(null);
    setBusy(key);
    try {
      await fn();
      setAmount('');
      await queryClient.invalidateQueries({ queryKey: ['groupLoan', groupLoanId] });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(null);
    }
  }

  function renderInstallment({ item }: { item: GroupLoanInstallment }) {
    return (
      <View style={styles.installmentRow}>
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">
            #{item.sequence} — {new Date(item.due_date).toLocaleDateString()}
          </ThemedText>
          <ThemedText type="small">{item.status.replaceAll('_', ' ')}</ThemedText>
        </View>
        <View style={{ alignItems: 'flex-end' }}>
          <ThemedText>{item.amount_due_formatted}</ThemedText>
          {item.remaining > 0 && item.remaining < item.amount_due ? (
            <ThemedText type="small">Remaining: {item.remaining_formatted}</ThemedText>
          ) : null}
        </View>
      </View>
    );
  }

  if (groupLoan.isLoading) {
    return <ActivityIndicator style={{ marginTop: 24 }} />;
  }
  if (groupLoan.isError || !groupLoan.data) {
    return <ThemedText style={styles.empty}>Could not load this group loan.</ThemedText>;
  }

  const data = groupLoan.data;
  const parsedAmount = Math.round(Number(amount) * 100);

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
            <ThemedText type="small">
              {data.customer_name ?? ''}
              {data.customer_name && data.loan_group?.name ? ` · ${data.loan_group.name}` : data.loan_group?.name ?? ''}
            </ThemedText>
            <ThemedText>Loan amount: {data.principal_amount_formatted}</ThemedText>
            <ThemedText>Security deposit: {data.security_deposit_amount_formatted}</ThemedText>
            <ThemedText>Amount to be paid: {data.periodic_amount_formatted}</ThemedText>
            <ThemedText>Outstanding: {data.outstanding_balance_formatted}</ThemedText>
            <ThemedText type="small">
              Status: {data.status.replaceAll('_', ' ')} · deposit {data.deposit_status}
            </ThemedText>
          </View>

          {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

          {data.status === 'draft' && data.deposit_status === 'pending' ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Record Security Deposit</ThemedText>
              <ThemedText type="small">Due: {data.security_deposit_amount_formatted}</ThemedText>
              <Pressable
                style={[styles.button, busy && styles.buttonDisabled]}
                disabled={!!busy}
                onPress={() => run('deposit', () => recordGroupLoanDeposit(data.id, data.security_deposit_amount))}
              >
                {busy === 'deposit' ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <ThemedText style={styles.buttonText}>Record Deposit</ThemedText>
                )}
              </Pressable>
            </View>
          ) : null}

          {data.status === 'draft' && data.deposit_status === 'held' ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Activate Loan</ThemedText>
              <ThemedText type="small">Generates the repayment schedule and disburses the principal.</ThemedText>
              <Pressable
                style={[styles.button, busy && styles.buttonDisabled]}
                disabled={!!busy}
                onPress={() => run('activate', () => activateGroupLoan(data.id))}
              >
                {busy === 'activate' ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <ThemedText style={styles.buttonText}>Activate</ThemedText>
                )}
              </Pressable>
            </View>
          ) : null}

          {data.status === 'active' ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Record Repayment</ThemedText>
              <TextInput
                style={styles.input}
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={setAmount}
                placeholder="Amount (GHS)"
              />
              <Pressable
                style={[styles.button, busy && styles.buttonDisabled]}
                disabled={!!busy}
                onPress={() => {
                  if (!parsedAmount || parsedAmount <= 0) return setError('Enter a valid amount.');
                  run('repay', () => recordGroupLoanRepayment(data.id, parsedAmount));
                }}
              >
                {busy === 'repay' ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <ThemedText style={styles.buttonText}>Record Repayment</ThemedText>
                )}
              </Pressable>

              {data.deposit_status === 'held' ? (
                <Pressable
                  style={[styles.buttonSecondary, busy && styles.buttonDisabled]}
                  disabled={!!busy}
                  onPress={() => run('applyDeposit', () => applyGroupLoanDeposit(data.id))}
                >
                  {busy === 'applyDeposit' ? (
                    <ActivityIndicator color={Palette.primary500} />
                  ) : (
                    <ThemedText style={styles.buttonSecondaryText}>Apply deposit to balance</ThemedText>
                  )}
                </Pressable>
              ) : null}
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
    gap: 8,
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
  buttonSecondary: {
    borderWidth: 1,
    borderColor: Palette.primary500,
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700' },
  buttonSecondaryText: { color: Palette.primary500, fontWeight: '700' },
  error: { color: Palette.danger },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
