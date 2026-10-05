import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';

import { apiErrorMessage } from '@/api/client';
import { createWithdrawalRequest } from '@/api/withdrawals';
import {
  AmountInput,
  Button,
  Card,
  confirmAction,
  Input,
  KeyValueRow,
  Notice,
  ResultView,
  Screen,
} from '@/components/ui';
import { useOnline } from '@/hooks/use-network';
import type { SavingsProductType } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

export default function WithdrawScreen() {
  const online = useOnline();
  const { accountId, accountNumber, balanceFormatted, productType, maturesAt, maturedAt } = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    balanceFormatted?: string;
    productType?: SavingsProductType | '';
    maturesAt?: string;
    maturedAt?: string;
  }>();
  const isBlockedFd = productType === 'fixed_deposit' && !maturedAt;

  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submittedAmount, setSubmittedAmount] = useState<number | null>(null);
  const submittingRef = useRef(false);

  const amountMinor = parseAmountToMinor(amount);

  async function submit(minor: number) {
    // Guard against a second tap between confirm and the first request
    // resolving. The backend has no idempotency key for withdrawal requests
    // yet, so the client must not send twice.
    if (submittingRef.current || submittedAmount !== null) {
      return;
    }
    submittingRef.current = true;
    setSubmitting(true);
    try {
      await createWithdrawalRequest({
        savings_account_id: accountId,
        amount: minor,
        reason: reason.trim() || undefined,
      });
      setSubmittedAmount(minor);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    setError(null);
    if (amountMinor === null || amountMinor <= 0) {
      setError('Enter the amount you want to withdraw.');
      return;
    }
    confirmAction({
      title: 'Request this withdrawal?',
      message: `You are asking to withdraw ${formatMoney(amountMinor)} from account ${accountNumber ?? ''}. Your branch reviews every request before paying out.`,
      confirmLabel: `Request ${formatMoney(amountMinor)}`,
      onConfirm: () => void submit(amountMinor),
    });
  }

  if (submittedAmount !== null) {
    return (
      <Screen footer={<Button title="Back to account" size="lg" onPress={() => router.back()} />}>
        <ResultView
          tone="success"
          title="Withdrawal request sent"
          amount={formatMoney(submittedAmount)}
          message="Your branch will review it. Your balance only changes once the withdrawal is approved and paid."
        />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <Button
          title={amountMinor ? `Request ${formatMoney(amountMinor)}` : 'Request withdrawal'}
          icon="arrowUp"
          size="lg"
          loading={submitting}
          loadingTitle="Sending request…"
          disabled={isBlockedFd || !online}
          onPress={handleSubmit}
        />
      }
    >
      <Card>
        <KeyValueRow label="From account" value={accountNumber || '—'} />
        <KeyValueRow label="Current savings balance" value={displayFormatted(balanceFormatted)} emphasis />
      </Card>

      {isBlockedFd ? (
        <Notice
          tone="warning"
          title="Not yet available"
          message={`Fixed deposits can't be withdrawn before maturity${maturesAt ? ` (${formatDate(maturesAt)})` : ''}.`}
        />
      ) : null}
      {!online ? <Notice tone="warning" message="Withdrawal requests need an internet connection." /> : null}

      <AmountInput
        label="Amount to withdraw"
        value={amount}
        onChangeText={setAmount}
        editable={!isBlockedFd}
        error={error}
      />
      <Input
        label="Reason"
        optional
        value={reason}
        onChangeText={setReason}
        multiline
        editable={!isBlockedFd}
        placeholder="e.g. School fees"
      />
      <Notice tone="info" message="Requests are reviewed by your branch before any money is paid out." />
    </Screen>
  );
}
