import { useLocalSearchParams, router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { drainOutbox } from '@/sync/engine';
import { enqueueCollection } from '@/sync/ops';
import { getCurrentPositionSafe } from '@/utils/location';

/**
 * Records a susu deposit offline-first: the write lands in the local outbox
 * immediately (so it works with zero connectivity), then a sync attempt is
 * fired in the background. GPS is attached on a best-effort basis.
 */
export default function CollectScreen() {
  const params = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    customerName?: string;
    contributionAmount?: string;
    balanceFormatted?: string;
  }>();

  const [amount, setAmount] = useState(
    params.contributionAmount ? (Number(params.contributionAmount) / 100).toFixed(2) : '',
  );
  const [submitting, setSubmitting] = useState(false);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setSubmitting(true);
    try {
      const location = await getCurrentPositionSafe();

      await enqueueCollection({
        savings_account_id: params.accountId,
        amount: Math.round(parsed * 100),
        latitude: location?.latitude,
        longitude: location?.longitude,
      });

      setSavedMessage('Collection saved. It will sync automatically.');
      // Fire-and-forget: don't block the agent's next collection on network.
      void drainOutbox();

      setTimeout(() => router.back(), 900);
    } catch {
      setError('Could not save the collection locally. Please try again.');
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

      <ThemedText type="small">Amount (GHS)</ThemedText>
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={amount}
        onChangeText={setAmount}
        placeholder="0.00"
        autoFocus
      />

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={styles.success}>{savedMessage}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Save Collection</ThemedText>}
      </Pressable>

      <ThemedText type="small" style={styles.hint}>
        Works offline — this is saved on your device immediately and synced when you have a connection.
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
