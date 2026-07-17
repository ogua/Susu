import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, StyleSheet } from 'react-native';

import { getTodaySummary } from '@/api/summaries';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen, StatTile, StatTileRow } from '@/components/ui';
import { Palette } from '@/constants/theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueDailySummary } from '@/sync/ops';
import { formatMoney } from '@/utils/money';

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
    <Screen>
      {summary.isLoading ? (
        <ActivityIndicator />
      ) : summary.data ? (
        <StatTileRow>
          <StatTile
            label="Collected today"
            value={summary.data.summary.collections_total_formatted ?? '—'}
            hint={`${summary.data.summary.collections_count} collection(s)`}
          />
          <StatTile
            label="Expected cash in hand"
            value={formatMoney(summary.data.cash_in_hand)}
            accentColor={Palette.primary600}
          />
        </StatTileRow>
      ) : (
        <Card>
          <ThemedText type="small" themeColor="textSecondary">
            Could not load today&apos;s summary (offline?). You can still declare your cash — it
            syncs automatically.
          </ThemedText>
        </Card>
      )}

      <Card style={styles.form}>
        <ThemedText type="subtitle">Declare your cash</ThemedText>
        <Input
          label="Cash you are declaring (GHS)"
          keyboardType="decimal-pad"
          value={declaredCash}
          onChangeText={setDeclaredCash}
          placeholder="0.00"
          error={error}
        />
        <Input
          label="Notes (optional)"
          value={notes}
          onChangeText={setNotes}
          multiline
          style={styles.notes}
        />
        {savedMessage ? <ThemedText style={{ color: Palette.success }}>{savedMessage}</ThemedText> : null}
        <Button title="Submit Day Summary" loading={submitting} onPress={handleSubmit} />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 12 },
  notes: { minHeight: 80, textAlignVertical: 'top' },
});
