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
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useOnline } from '@/hooks/use-network';
import type { SavingsProductType } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

export default function WithdrawScreen() {
  const online = useOnline();
  const { accountId, accountNumber, balanceFormatted, productType, maturesAt, maturedAt, parValue } = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    balanceFormatted?: string;
    productType?: SavingsProductType | '';
    maturesAt?: string;
    maturedAt?: string;
    parValue?: string;
  }>();
  const isBlockedFd = productType === 'fixed_deposit' && !maturedAt;
  // Share capital is redeemed in whole shares (mirrors RequestWithdrawalAction).
  const shareParValue = productType === 'shares' ? Number(parValue) || 0 : 0;

  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submittedAmount, setSubmittedAmount] = useState<number | null>(null);
  const submittingRef = useRef(false);
  const { key, rotate } = useIdempotencyKey();

  const amountMinor = parseAmountToMinor(amount);

  async function submit(minor: number) {
    // Ref guard stops a second tap; client_reference makes a retry after a
    // timeout return the original request instead of creating another.
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
        client_reference: key,
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
    if (shareParValue > 0 && amountMinor % shareParValue !== 0) {
      setError(`Share withdrawals must be a whole number of shares (${formatMoney(shareParValue)} each).`);
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
        onChangeText={(text) => {
          setAmount(text);
          rotate();
        }}
        editable={!isBlockedFd}
        error={error}
        hint={shareParValue > 0 ? `Whole shares only: multiples of ${formatMoney(shareParValue)}.` : undefined}
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
