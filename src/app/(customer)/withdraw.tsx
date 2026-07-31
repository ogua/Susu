import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { createWithdrawalRequest } from '@/api/withdrawals';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen } from '@/components/ui';
import { Palette } from '@/constants/theme';
import type { SavingsProductType } from '@/types/api';

const FD_BLOCKED_MESSAGE = 'Fixed deposits cannot be withdrawn before their maturity date.';

export default function WithdrawScreen() {
  const { accountId, productType, maturedAt } = useLocalSearchParams<{
    accountId: string;
    productType?: SavingsProductType | '';
    maturesAt?: string;
    maturedAt?: string;
  }>();
  const isBlockedFd = productType === 'fixed_deposit' && !maturedAt;

  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  async function handleSubmit() {
    setError(null);
    if (isBlockedFd) {
      setError(FD_BLOCKED_MESSAGE);
      return;
    }
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setSubmitting(true);
    try {
      await createWithdrawalRequest({
        savings_account_id: accountId,
        amount: Math.round(parsed * 100),
        reason: reason.trim() || undefined,
      });

      setSuccess(true);
      setTimeout(() => router.back(), 900);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Screen>
      <Card style={styles.form}>
        {isBlockedFd ? (
          <ThemedText style={{ color: Palette.danger }}>{FD_BLOCKED_MESSAGE}</ThemedText>
        ) : null}
        <Input
          label="Amount (GHS)"
          keyboardType="decimal-pad"
          value={amount}
          onChangeText={setAmount}
          placeholder="0.00"
          autoFocus
          editable={!isBlockedFd}
          error={isBlockedFd ? undefined : error}
        />
        <Input
          label="Reason (optional)"
          value={reason}
          onChangeText={setReason}
          multiline
          editable={!isBlockedFd}
          style={styles.notes}
        />
        {success ? (
          <ThemedText style={{ color: Palette.success }}>Withdrawal request submitted.</ThemedText>
        ) : null}
        <Button title="Submit Request" loading={submitting} disabled={isBlockedFd} onPress={handleSubmit} />
        <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
          Your branch reviews withdrawal requests before paying out.
        </ThemedText>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 12 },
  notes: { minHeight: 80, textAlignVertical: 'top' },
  hint: { textAlign: 'center' },
});
