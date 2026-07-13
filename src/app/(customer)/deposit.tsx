import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
import type { MobileMoneyProvider } from '@/types/api';

const PROVIDERS: { value: MobileMoneyProvider; label: string }[] = [
  { value: 'mtn', label: 'MTN' },
  { value: 'vod', label: 'Telecel' },
  { value: 'atl', label: 'AirtelTigo' },
];

/** Self-service deposit: RecordCollectionAction allows a customer to pay into their own account. */
export default function DepositScreen() {
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
    <View style={styles.container}>
      <ThemedText type="small">Amount (GHS)</ThemedText>
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={amount}
        onChangeText={setAmount}
        placeholder="0.00"
        autoFocus
      />

      <ThemedText type="small">Mobile money number</ThemedText>
      <TextInput
        style={styles.phoneInput}
        keyboardType="phone-pad"
        value={phone}
        onChangeText={setPhone}
        placeholder="024xxxxxxx"
      />

      <View style={styles.methodRow}>
        {PROVIDERS.map((option) => (
          <Pressable
            key={option.value}
            style={[styles.methodButton, provider === option.value && styles.methodButtonActive]}
            onPress={() => setProvider(option.value)}
          >
            <ThemedText style={provider === option.value ? styles.methodTextActive : undefined}>
              {option.label}
            </ThemedText>
          </Pressable>
        ))}
      </View>

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Pay Now</ThemedText>}
      </Pressable>

      <ThemedText type="small" style={styles.hint}>
        You&apos;ll get a PIN prompt on your phone to approve the payment.
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, gap: 12 },
  input: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 14,
    fontSize: 22,
    fontWeight: '600',
  },
  phoneInput: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  methodRow: { flexDirection: 'row', gap: 8 },
  methodButton: {
    flex: 1,
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingVertical: 10,
    alignItems: 'center',
  },
  methodButtonActive: { backgroundColor: '#208AEF', borderColor: '#208AEF' },
  methodTextActive: { color: '#ffffff', fontWeight: '700' },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700', fontSize: 16 },
  error: { color: '#d11a2a' },
  hint: { textAlign: 'center', opacity: 0.6, marginTop: 8 },
});
