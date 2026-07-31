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
import { formatMoney } from '@/utils/money';

const PROVIDERS: { value: MobileMoneyProvider; label: string }[] = [
  { value: 'mtn', label: 'MTN' },
  { value: 'vod', label: 'Telecel' },
  { value: 'atl', label: 'AirtelTigo' },
];

/**
 * Self-service share purchase: routed server-side to BuySharesAction
 * (not RecordCollectionAction) because the account's product type is
 * Shares — see App\Actions\Payments\VerifyPaymentIntentAction::complete().
 */
export default function BuySharesScreen() {
  const theme = useTheme();
  const { accountId, parValue } = useLocalSearchParams<{
    accountId: string;
    parValue?: string;
  }>();
  const parValueMinorUnits = Number(parValue) || 0;

  const [shares, setShares] = useState('');
  const [phone, setPhone] = useState('');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const sharesCount = Number(shares) || 0;
  const totalAmount = sharesCount > 0 ? sharesCount * parValueMinorUnits : 0;

  async function handleSubmit() {
    setError(null);
    if (!Number.isInteger(sharesCount) || sharesCount <= 0) {
      setError('Enter a valid number of shares.');
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
        amount: totalAmount,
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
          label="Number of shares"
          keyboardType="number-pad"
          value={shares}
          onChangeText={setShares}
          placeholder="0"
          autoFocus
          error={error}
        />

        {sharesCount > 0 ? (
          <ThemedText type="small" themeColor="textSecondary">
            {sharesCount} shares × {formatMoney(parValueMinorUnits)} = {formatMoney(totalAmount)}
          </ThemedText>
        ) : null}

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

        <Button title="Buy Shares" loading={submitting} onPress={handleSubmit} />

        <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
          You&apos;ll get a PIN prompt on your phone to approve the payment.
        </ThemedText>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 12 },
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
