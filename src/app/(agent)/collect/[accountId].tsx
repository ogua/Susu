import { useLocalSearchParams, router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueCollection } from '@/sync/ops';
import type { MobileMoneyProvider } from '@/types/api';
import { getCurrentPositionSafe } from '@/utils/location';

const PROVIDERS: { value: MobileMoneyProvider; label: string }[] = [
  { value: 'mtn', label: 'MTN' },
  { value: 'vod', label: 'Telecel' },
  { value: 'atl', label: 'AirtelTigo' },
];

/**
 * Cash stays offline-first (outbox); mobile money needs a live round trip to
 * Paystack, so it bypasses the outbox entirely and goes straight to the
 * verify screen once the charge is initiated.
 */
export default function CollectScreen() {
  const theme = useTheme();
  const params = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    customerName?: string;
    contributionAmount?: string;
    balanceFormatted?: string;
  }>();

  const [method, setMethod] = useState<'cash' | 'mobile_money'>('cash');
  const [amount, setAmount] = useState(
    params.contributionAmount ? (Number(params.contributionAmount) / 100).toFixed(2) : '',
  );
  const [phone, setPhone] = useState('');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmitCash() {
    const location = await getCurrentPositionSafe();

    await enqueueCollection({
      savings_account_id: params.accountId,
      amount: Math.round(Number(amount) * 100),
      latitude: location?.latitude,
      longitude: location?.longitude,
    });

    setSavedMessage('Collection saved. It will sync automatically.');
    // Fire-and-forget: don't block the agent's next collection on network.
    void drainOutbox();

    setTimeout(() => router.back(), 900);
  }

  async function handleSubmitMobileMoney() {
    if (!phone.trim()) {
      setError('Enter the mobile money number.');
      return;
    }

    const intent = await chargeMobileMoney({
      savings_account_id: params.accountId,
      amount: Math.round(Number(amount) * 100),
      phone: phone.trim(),
      provider,
    });

    router.push({
      pathname: '/(agent)/payment-verify',
      params: { intentId: intent.id, amountFormatted: intent.amount_formatted },
    });
  }

  async function handleSubmit() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setSubmitting(true);
    try {
      if (method === 'cash') {
        await handleSubmitCash();
      } else {
        await handleSubmitMobileMoney();
      }
    } catch (err) {
      setError(method === 'cash' ? 'Could not save the collection locally. Please try again.' : apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  function segment(options: { value: string; label: string }[], selected: string, onSelect: (value: string) => void) {
    return (
      <View style={styles.segmentRow}>
        {options.map((option) => {
          const active = selected === option.value;

          return (
            <Pressable
              key={option.value}
              style={[
                styles.segment,
                { borderColor: active ? Palette.primary500 : theme.border },
                active && styles.segmentActive,
              ]}
              onPress={() => onSelect(option.value)}
            >
              <ThemedText type="smallBold" style={active ? styles.segmentTextActive : undefined}>
                {option.label}
              </ThemedText>
            </Pressable>
          );
        })}
      </View>
    );
  }

  return (
    <Screen>
      <Card>
        <ThemedText type="subtitle">{params.customerName || 'Customer'}</ThemedText>
        <ThemedText type="small" themeColor="textSecondary">
          {params.accountNumber}
          {params.balanceFormatted ? ` · Balance ${params.balanceFormatted}` : ''}
        </ThemedText>
      </Card>

      <Card style={styles.form}>
        {segment(
          [
            { value: 'cash', label: 'Cash' },
            { value: 'mobile_money', label: 'Mobile Money' },
          ],
          method,
          (value) => setMethod(value as 'cash' | 'mobile_money'),
        )}

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

        {method === 'mobile_money' ? (
          <>
            <Input
              label="Mobile money number"
              keyboardType="phone-pad"
              value={phone}
              onChangeText={setPhone}
              placeholder="024xxxxxxx"
            />
            {segment(PROVIDERS, provider, (value) => setProvider(value as MobileMoneyProvider))}
          </>
        ) : null}

        {savedMessage ? <ThemedText style={{ color: Palette.success }}>{savedMessage}</ThemedText> : null}

        <Button
          title={method === 'cash' ? 'Save Collection' : 'Charge Mobile Money'}
          loading={submitting}
          onPress={handleSubmit}
        />

        <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
          {method === 'cash'
            ? 'Works offline — this is saved on your device immediately and synced when you have a connection.'
            : 'Requires an internet connection — the customer will get a PIN prompt on their phone.'}
        </ThemedText>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 12 },
  amountInput: { fontSize: 22, fontWeight: '600' },
  segmentRow: { flexDirection: 'row', gap: 8 },
  segment: {
    flex: 1,
    borderWidth: 1,
    borderRadius: Radii.sm,
    paddingVertical: 10,
    alignItems: 'center',
  },
  segmentActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  segmentTextActive: { color: '#ffffff' },
  hint: { textAlign: 'center' },
});
