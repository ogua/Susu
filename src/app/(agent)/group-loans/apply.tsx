import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { issueGroupMemberLoan } from '@/api/groupLoans';
import { getLoanGroup, getLoanGroups } from '@/api/loanGroups';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Button,
  Card,
  ChipSelect,
  confirmAction,
  ErrorState,
  Field,
  Input,
  LoadingState,
  Notice,
  ResultView,
  Screen,
  SegmentedControl,
  type Option,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import type { RepaymentFrequency } from '@/types/api';
import { isValidIsoDate, maskDateInput } from '@/utils/format';
import { formatMoney, parseAmountToMinor } from '@/utils/money';

const FREQUENCIES: Option<RepaymentFrequency>[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
];

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Mirrors the backend PeriodicScheduleGenerator so the agent sees the spread before submitting. */
function previewSchedule(principalMinor: number, periodicMinor: number, frequency: RepaymentFrequency): string | null {
  if (principalMinor <= 0 || periodicMinor <= 0) {
    return null;
  }
  const count =
    periodicMinor >= principalMinor
      ? 1
      : Math.floor(principalMinor / periodicMinor) + (principalMinor % periodicMinor > 0 ? 1 : 0);
  const last = principalMinor - periodicMinor * (count - 1);

  return `${count} ${frequency} payment${count === 1 ? '' : 's'} of ${formatMoney(periodicMinor)}${count > 1 ? `; final payment ${formatMoney(last)}` : ''}.`;
}

export default function IssueGroupMemberLoanScreen() {
  const loanGroups = useQuery({ queryKey: ['loan-groups'], queryFn: () => getLoanGroups() });

  const [groupId, setGroupId] = useState<string | null>(null);
  const [memberId, setMemberId] = useState<string | null>(null);
  const [principal, setPrincipal] = useState('');
  const [deposit, setDeposit] = useState('');
  const [periodic, setPeriodic] = useState('');
  const [frequency, setFrequency] = useState<RepaymentFrequency>('weekly');
  const [startDate, setStartDate] = useState(todayIso());
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<string | null>(null);
  const submittingRef = useRef(false);
  const { key, rotate } = useIdempotencyKey();

  const groupDetail = useQuery({
    queryKey: ['loan-group', groupId],
    queryFn: () => getLoanGroup(groupId as string),
    enabled: !!groupId,
  });

  // open_loan also covers drafts still awaiting deposit/activation; fall back to
  // active_loan for servers that predate the field.
  const members = (groupDetail.data?.members ?? []).filter(
    (m) => m.status === 'active' && !(m.open_loan !== undefined ? m.open_loan : m.active_loan),
  );
  const member = members.find((m) => m.id === memberId) ?? null;
  const principalMinor = parseAmountToMinor(principal) ?? 0;
  const periodicMinor = parseAmountToMinor(periodic) ?? 0;
  const schedule = previewSchedule(principalMinor, periodicMinor, frequency);

  function changed<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      rotate();
    };
  }

  async function submit(depositMinor: number) {
    if (submittingRef.current || issued || !groupId || !member) return;
    submittingRef.current = true;
    setSubmitting(true);
    try {
      await issueGroupMemberLoan({
        loan_group_id: groupId,
        customer_id: member.customer_id,
        principal_amount: principalMinor,
        security_deposit_amount: depositMinor,
        periodic_amount: periodicMinor,
        repayment_frequency: frequency,
        start_date: startDate,
        notes: notes.trim() || undefined,
        client_reference: key,
      });
      setIssued(member.customer_name);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    setError(null);
    if (!groupId) return setError('Choose a loan group.');
    if (!member) return setError('Choose the member receiving the loan.');
    if (!principalMinor) return setError('Enter the loan amount.');
    if (!periodicMinor) return setError('Enter the amount to repay each period.');
    if (!isValidIsoDate(startDate)) return setError('Enter a real first payment date, e.g. 2026-11-01.');
    const depositMinor = deposit.trim() ? parseAmountToMinor(deposit) : 0;
    if (depositMinor === null) return setError('Enter a valid security deposit, or leave it empty.');

    confirmAction({
      title: 'Issue this loan?',
      message: `${formatMoney(principalMinor)} to ${member.customer_name}. ${schedule ?? ''} The loan stays in draft until the deposit is recorded and it is activated.`,
      confirmLabel: `Issue ${formatMoney(principalMinor)}`,
      onConfirm: () => void submit(depositMinor),
    });
  }

  if (issued) {
    return (
      <Screen footer={<Button title="View group loans" size="lg" onPress={() => router.replace('/(agent)/group-loans')} />}>
        <ResultView
          tone="success"
          title="Loan created as draft"
          amount={formatMoney(principalMinor)}
          message={`Next: record ${issued}'s security deposit, then activate the loan to disburse it.`}
        />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <Button
          title={principalMinor ? `Issue ${formatMoney(principalMinor)}` : 'Issue loan'}
          icon="send"
          size="lg"
          loading={submitting}
          loadingTitle="Issuing…"
          onPress={handleSubmit}
        />
      }
    >
      <Field label="Loan group">
        {loanGroups.isLoading ? (
          <LoadingState label="Loading loan groups…" />
        ) : loanGroups.isError ? (
          <ErrorState title="Couldn't load loan groups" onRetry={() => void loanGroups.refetch()} />
        ) : (
          <ChipSelect
            accessibilityLabel="Loan group"
            options={(loanGroups.data?.data ?? []).map((group) => ({ value: group.id, label: group.name }))}
            value={groupId}
            onChange={(value) => {
              setGroupId(value);
              setMemberId(null);
              rotate();
            }}
          />
        )}
      </Field>

      {groupId ? (
        <Field label="Member">
          {groupDetail.isLoading ? (
            <LoadingState label="Loading members…" />
          ) : members.length === 0 ? (
            <Notice tone="info" message="Every active member already has a loan. Add members from the web or desktop app." />
          ) : (
            <ChipSelect
              accessibilityLabel="Member"
              options={members.map((m) => ({ value: m.id, label: m.customer_name }))}
              value={memberId}
              onChange={changed(setMemberId)}
            />
          )}
        </Field>
      ) : null}

      <Card style={styles.section}>
        <AmountInput size="md" label="Loan amount" value={principal} onChangeText={changed(setPrincipal)} />
        <AmountInput size="md" label="Security deposit" value={deposit} onChangeText={changed(setDeposit)} hint="Leave empty if no deposit is required." />
        <AmountInput size="md" label="Repayment each period" value={periodic} onChangeText={changed(setPeriodic)} />
        <Field label="Repayment frequency">
          <SegmentedControl accessibilityLabel="Repayment frequency" options={FREQUENCIES} value={frequency} onChange={changed(setFrequency)} />
        </Field>
        <Input
          label="First payment date"
          icon="calendar"
          value={startDate}
          onChangeText={(text) => {
            setStartDate(maskDateInput(text));
            rotate();
          }}
          placeholder="YYYY-MM-DD"
          keyboardType="number-pad"
          maxLength={10}
        />
      </Card>

      <Notice
        tone="info"
        icon="calendar"
        title="Payment schedule"
        message={schedule ?? 'Enter a loan amount and a repayment amount to preview the schedule.'}
      />

      <Input label="Notes" optional value={notes} onChangeText={setNotes} multiline />

      {error ? <Notice tone="danger" message={error} /> : null}
      <ThemedText type="caption" themeColor="textMuted" style={styles.center}>
        The loan is created as a draft. No money moves until the deposit is recorded and the loan is activated.
      </ThemedText>
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: { gap: Spacing.three },
  center: { textAlign: 'center' },
});
