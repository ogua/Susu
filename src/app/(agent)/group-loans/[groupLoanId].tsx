import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { getGroupLoan, recordGroupLoanRepayment } from '@/api/groupLoans';
import { ThemedText } from '@/components/themed-text';
import type { GroupLoanBorrower, LoanInstallment } from '@/types/api';
import { Palette } from '@/constants/theme';

/**
 * No approve/reject/disburse actions here — mirrors the individual-loan
 * detail screen exactly: those decisions have no direct API route on mobile
 * either (Filament web + the desktop app's local-first sync are the only
 * paths), so this screen only supports viewing and recording a repayment.
 */
export default function GroupLoanDetailScreen() {
  const { groupLoanId } = useLocalSearchParams<{ groupLoanId: string }>();
  const queryClient = useQueryClient();

  const [selectedBorrowerId, setSelectedBorrowerId] = useState<string | null>(null);
  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const groupLoan = useQuery({
    queryKey: ['groupLoan', groupLoanId],
    queryFn: () => getGroupLoan(groupLoanId),
    enabled: !!groupLoanId,
  });

  async function handleRecordRepayment() {
    setError(null);
    if (!selectedBorrowerId) {
      setError('Select which member is paying.');
      return;
    }
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setSubmitting(true);
    try {
      await recordGroupLoanRepayment(groupLoanId, selectedBorrowerId, Math.round(parsed * 100));
      setAmount('');
      await queryClient.invalidateQueries({ queryKey: ['groupLoan', groupLoanId] });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
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

  if (groupLoan.isLoading) {
    return <ActivityIndicator style={{ marginTop: 24 }} />;
  }
  if (groupLoan.isError || !groupLoan.data) {
    return <ThemedText style={styles.empty}>Could not load this group loan.</ThemedText>;
  }

  const data = groupLoan.data;
  const borrowers = data.borrowers ?? [];

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
            <ThemedText type="small">{data.loan_group?.name}</ThemedText>
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

          {borrowers.length > 0 ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Members</ThemedText>
              {borrowers.map((borrower: GroupLoanBorrower) => (
                <View key={borrower.id} style={styles.borrowerRow}>
                  <ThemedText type="small">{borrower.customer_name}</ThemedText>
                  <ThemedText type="small">
                    {borrower.share_outstanding_formatted} / {borrower.share_principal_formatted}
                  </ThemedText>
                </View>
              ))}
            </View>
          ) : null}

          {data.status === 'disbursed' && borrowers.length > 0 ? (
            <View style={styles.card}>
              <ThemedText type="subtitle">Record Repayment</ThemedText>
              <ThemedText type="small">Paying member</ThemedText>
              <View style={styles.segmentWrapRow}>
                {borrowers.map((borrower: GroupLoanBorrower) => {
                  const active = selectedBorrowerId === borrower.id;

                  return (
                    <Pressable
                      key={borrower.id}
                      style={[
                        styles.segmentWrapChip,
                        { borderColor: active ? Palette.primary500 : Palette.border },
                        active && styles.segmentActive,
                      ]}
                      onPress={() => setSelectedBorrowerId(borrower.id)}
                    >
                      <ThemedText type="smallBold" style={active ? styles.segmentTextActive : undefined}>
                        {borrower.customer_name}
                      </ThemedText>
                    </Pressable>
                  );
                })}
              </View>
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
  borrowerRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4 },
  installmentRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 10 },
  separator: { height: 1, backgroundColor: Palette.border },
  segmentWrapRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  segmentWrapChip: {
    borderWidth: 1,
    borderRadius: 10,
    paddingVertical: 8,
    paddingHorizontal: 14,
  },
  segmentActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  segmentTextActive: { color: '#ffffff' },
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
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700' },
  error: { color: Palette.danger },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
