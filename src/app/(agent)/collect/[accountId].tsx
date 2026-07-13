import { useLocalSearchParams, router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
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

  return (
    <View style={styles.container}>
      <View style={styles.card}>
        <ThemedText type="subtitle">{params.customerName || 'Customer'}</ThemedText>
        <ThemedText type="small">{params.accountNumber}</ThemedText>
        {params.balanceFormatted ? (
          <ThemedText type="small">Current balance: {params.balanceFormatted}</ThemedText>
        ) : null}
      </View>

      <View style={styles.methodRow}>
        <Pressable
          style={[styles.methodButton, method === 'cash' && styles.methodButtonActive]}
          onPress={() => setMethod('cash')}
        >
          <ThemedText style={method === 'cash' ? styles.methodTextActive : undefined}>Cash</ThemedText>
        </Pressable>
        <Pressable
          style={[styles.methodButton, method === 'mobile_money' && styles.methodButtonActive]}
          onPress={() => setMethod('mobile_money')}
        >
          <ThemedText style={method === 'mobile_money' ? styles.methodTextActive : undefined}>Mobile Money</ThemedText>
        </Pressable>
      </View>

      <ThemedText type="small">Amount (GHS)</ThemedText>
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={amount}
        onChangeText={setAmount}
        placeholder="0.00"
        autoFocus
      />

      {method === 'mobile_money' ? (
        <>
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
        </>
      ) : null}

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={styles.success}>{savedMessage}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <ThemedText style={styles.buttonText}>
            {method === 'cash' ? 'Save Collection' : 'Charge Mobile Money'}
          </ThemedText>
        )}
      </Pressable>

      <ThemedText type="small" style={styles.hint}>
        {method === 'cash'
          ? 'Works offline — this is saved on your device immediately and synced when you have a connection.'
          : 'Requires an internet connection — the customer will get a PIN prompt on their phone.'}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, gap: 12 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 4,
    backgroundColor: '#ffffff',
  },
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
  success: { color: '#1a8a3d' },
  hint: { textAlign: 'center', opacity: 0.6, marginTop: 8 },
});
