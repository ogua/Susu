import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { MobileMoneyProvider } from '@/types/api';

const PROVIDERS: { value: MobileMoneyProvider; label: string }[] = [
  { value: 'mtn', label: 'MTN' },
  { value: 'vod', label: 'Telecel' },
  { value: 'atl', label: 'AirtelTigo' },
];

/** Self-service deposit: RecordCollectionAction allows a customer to pay into their own account. */
export default function DepositScreen() {
  const theme = useTheme();
  const { accountId } = useLocalSearchParams<{ accountId: string }>();

  const [amount, setAmount] = useState('');
  const [phone, setPhone] = useState('');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }
    if (!phone.trim()) {
      setError('Enter your mobile money number.');
      return;
    }

    setSubmitting(true);
    try {
      const intent = await chargeMobileMoney({
        savings_account_id: accountId,
        amount: Math.round(parsed * 100),
        phone: phone.trim(),
        provider,
      });

      router.push({
        pathname: '/(customer)/payment-verify',
        params: { intentId: intent.id, amountFormatted: intent.amount_formatted },
      });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Screen>
      <Card style={styles.form}>
        <Input
          label="Amount (GHS)"
          keyboardType="decimal-pad"
          value={amount}
          onChangeText={setAmount}
          placeholder="0.00"
          autoFocus
          style={styles.amountInput}
          error={error}
        />
        <Input
          label="Mobile money number"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
          placeholder="024xxxxxxx"
        />

        <View style={styles.providerRow}>
          {PROVIDERS.map((option) => {
            const active = provider === option.value;

            return (
              <Pressable
                key={option.value}
                style={[
                  styles.provider,
                  { borderColor: active ? Palette.primary500 : theme.border },
                  active && styles.providerActive,
                ]}
                onPress={() => setProvider(option.value)}
              >
                <ThemedText type="smallBold" style={active ? styles.providerTextActive : undefined}>
                  {option.label}
                </ThemedText>
              </Pressable>
            );
          })}
        </View>

        <Button title="Pay Now" loading={submitting} onPress={handleSubmit} />

        <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
          You&apos;ll get a PIN prompt on your phone to approve the payment.
        </ThemedText>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 12 },
  amountInput: { fontSize: 22, fontWeight: '600' },
  providerRow: { flexDirection: 'row', gap: 8 },
  provider: {
    flex: 1,
    borderWidth: 1,
    borderRadius: Radii.sm,
    paddingVertical: 10,
    alignItems: 'center',
  },
  providerActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  providerTextActive: { color: '#ffffff' },
  hint: { textAlign: 'center' },
});
