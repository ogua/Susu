import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { issueGroupMemberLoan } from '@/api/groupLoans';
import { getLoanGroup, getLoanGroups } from '@/api/loanGroups';
import { ThemedText } from '@/components/themed-text';
import type { LoanGroup, LoanGroupMember, RepaymentFrequency } from '@/types/api';
import { Palette } from '@/constants/theme';

const FREQUENCIES: RepaymentFrequency[] = ['daily', 'weekly', 'monthly'];

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Mirrors the backend PeriodicScheduleGenerator so the agent sees the spread before submitting. */
function previewSchedule(principalMinor: number, periodicMinor: number, frequency: RepaymentFrequency): string {
  if (principalMinor <= 0 || periodicMinor <= 0) {
    return 'Enter a loan amount and a periodic amount to preview the schedule.';
  }
  const count =
    periodicMinor >= principalMinor
      ? 1
      : Math.floor(principalMinor / periodicMinor) + (principalMinor % periodicMinor > 0 ? 1 : 0);
  const last = principalMinor - periodicMinor * (count - 1);
  const fmt = (m: number) => `GHS ${(m / 100).toFixed(2)}`;

  return `${count} ${frequency} payment${count === 1 ? '' : 's'} of ${fmt(periodicMinor)}; final payment ${fmt(last)}.`;
}

export default function IssueGroupMemberLoanScreen() {
  const loanGroups = useQuery({ queryKey: ['loan-groups'], queryFn: () => getLoanGroups() });

  const [selectedGroup, setSelectedGroup] = useState<LoanGroup | null>(null);
  const [selectedMember, setSelectedMember] = useState<LoanGroupMember | null>(null);
  const [principal, setPrincipal] = useState('');
  const [deposit, setDeposit] = useState('');
  const [periodic, setPeriodic] = useState('');
  const [frequency, setFrequency] = useState<RepaymentFrequency>('weekly');
  const [startDate, setStartDate] = useState(todayIso());
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const groupDetail = useQuery({
    queryKey: ['loan-group', selectedGroup?.id],
    queryFn: () => getLoanGroup(selectedGroup!.id),
    enabled: !!selectedGroup,
  });

  const members = (groupDetail.data?.members ?? []).filter((m) => m.status === 'active' && !m.active_loan);

  const schedule = useMemo(
    () => previewSchedule(Math.round(Number(principal) * 100), Math.round(Number(periodic) * 100), frequency),
    [principal, periodic, frequency],
  );

  async function handleSubmit() {
    setError(null);
    if (!selectedGroup) return setError('Select a loan group.');
    if (!selectedMember) return setError('Select a member.');
    const principalMinor = Math.round(Number(principal) * 100);
    const periodicMinor = Math.round(Number(periodic) * 100);
    const depositMinor = Math.round(Number(deposit || '0') * 100);
    if (!principalMinor || principalMinor <= 0) return setError('Enter a valid loan amount.');
    if (!periodicMinor || periodicMinor <= 0) return setError('Enter a valid periodic amount.');

    setSubmitting(true);
    try {
      await issueGroupMemberLoan({
        loan_group_id: selectedGroup.id,
        customer_id: selectedMember.customer_id,
        principal_amount: principalMinor,
        security_deposit_amount: depositMinor,
        periodic_amount: periodicMinor,
        repayment_frequency: frequency,
        start_date: startDate,
        notes: notes.trim() || undefined,
      });

      router.replace('/(agent)/group-loans');
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ThemedText type="small">Loan group</ThemedText>
      {loanGroups.isLoading ? (
        <ActivityIndicator />
      ) : (
        <View style={styles.chipRow}>
          {(loanGroups.data?.data ?? []).map((group) => (
            <Pressable
              key={group.id}
              style={[styles.chip, selectedGroup?.id === group.id && styles.chipActive]}
              onPress={() => {
                setSelectedGroup(group);
                setSelectedMember(null);
              }}
            >
              <ThemedText style={selectedGroup?.id === group.id ? styles.chipTextActive : undefined}>
                {group.name}
              </ThemedText>
            </Pressable>
          ))}
        </View>
      )}

      {selectedGroup ? (
        <>
          <ThemedText type="small">Member</ThemedText>
          {groupDetail.isLoading ? (
            <ActivityIndicator />
          ) : members.length === 0 ? (
            <ThemedText type="small" style={{ opacity: 0.6 }}>
              No members without an active loan. Add members from the web or desktop app.
            </ThemedText>
          ) : (
            <View style={styles.chipRow}>
              {members.map((member) => (
                <Pressable
                  key={member.id}
                  style={[styles.chip, selectedMember?.id === member.id && styles.chipActive]}
                  onPress={() => setSelectedMember(member)}
                >
                  <ThemedText style={selectedMember?.id === member.id ? styles.chipTextActive : undefined}>
                    {member.customer_name}
                  </ThemedText>
                </Pressable>
              ))}
            </View>
          )}
        </>
      ) : null}

      <ThemedText type="small">Loan amount (GHS)</ThemedText>
      <TextInput style={styles.input} keyboardType="decimal-pad" value={principal} onChangeText={setPrincipal} placeholder="0.00" />

      <ThemedText type="small">Security deposit (GHS)</ThemedText>
      <TextInput style={styles.input} keyboardType="decimal-pad" value={deposit} onChangeText={setDeposit} placeholder="0.00" />

      <ThemedText type="small">Amount to be paid each period (GHS)</ThemedText>
      <TextInput style={styles.input} keyboardType="decimal-pad" value={periodic} onChangeText={setPeriodic} placeholder="0.00" />

      <ThemedText type="small">Frequency</ThemedText>
      <View style={styles.chipRow}>
        {FREQUENCIES.map((f) => (
          <Pressable key={f} style={[styles.chip, frequency === f && styles.chipActive]} onPress={() => setFrequency(f)}>
            <ThemedText style={frequency === f ? styles.chipTextActive : undefined}>{f}</ThemedText>
          </Pressable>
        ))}
      </View>

      <ThemedText type="small">First payment date (YYYY-MM-DD)</ThemedText>
      <TextInput style={styles.input} value={startDate} onChangeText={setStartDate} placeholder="2026-01-01" />

      <View style={styles.previewCard}>
        <ThemedText type="smallBold">Payment schedule</ThemedText>
        <ThemedText type="small">{schedule}</ThemedText>
      </View>

      <ThemedText type="small">Notes (optional)</ThemedText>
      <TextInput style={styles.input} value={notes} onChangeText={setNotes} multiline />

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Issue Loan</ThemedText>}
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 10 },
  input: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingVertical: 10,
    paddingHorizontal: 14,
  },
  chipActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  chipTextActive: { color: '#ffffff', fontWeight: '700' },
  previewCard: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    padding: 12,
    gap: 4,
    backgroundColor: '#f8fafc',
  },
  button: {
    backgroundColor: Palette.primary500,
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700', fontSize: 16 },
  error: { color: Palette.danger },
});
