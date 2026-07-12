import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { getTodaySummary } from '@/api/summaries';
import { ThemedText } from '@/components/themed-text';
import { drainOutbox } from '@/sync/engine';
import { enqueueDailySummary } from '@/sync/ops';

export default function DayCloseScreen() {
  const summary = useQuery({
    queryKey: ['agent', 'summary', 'today'],
    queryFn: getTodaySummary,
  });

  const [declaredCash, setDeclaredCash] = useState('');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    const parsed = Number(declaredCash);
    if (Number.isNaN(parsed) || parsed < 0) {
      setError('Enter the cash you are holding.');
      return;
    }

    setSubmitting(true);
    try {
      await enqueueDailySummary({
        declared_cash: Math.round(parsed * 100),
        notes: notes.trim() || undefined,
      });

      setSavedMessage('Day summary saved. It will sync automatically.');
      void drainOutbox();

      setTimeout(() => router.back(), 900);
    } catch {
      setError('Could not save the summary locally. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <View style={styles.card}>
        <ThemedText type="subtitle">Today so far</ThemedText>
        {summary.isLoading ? (
          <ActivityIndicator />
        ) : summary.data ? (
          <>
            <ThemedText>{summary.data.summary.collections_count} collections</ThemedText>
            <ThemedText>Total collected: {summary.data.summary.collections_total_formatted}</ThemedText>
            <ThemedText type="small">
              Expected cash in hand: GHS {(summary.data.cash_in_hand / 100).toFixed(2)}
            </ThemedText>
          </>
        ) : (
          <ThemedText type="small">Could not load today&apos;s summary (offline?).</ThemedText>
        )}
      </View>

      <ThemedText type="small">Cash you are declaring (GHS)</ThemedText>
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={declaredCash}
        onChangeText={setDeclaredCash}
        placeholder="0.00"
      />

      <ThemedText type="small">Notes (optional)</ThemedText>
      <TextInput
        style={[styles.input, styles.notes]}
        value={notes}
        onChangeText={setNotes}
        multiline
      />

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={styles.success}>{savedMessage}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Submit Day Summary</ThemedText>}
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 10 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 4,
    backgroundColor: '#ffffff',
    marginBottom: 8,
  },
  input: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  notes: { minHeight: 80, textAlignVertical: 'top' },
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
});
