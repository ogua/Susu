import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { getTodaySummary } from '@/api/summaries';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Badge,
  Button,
  Card,
  confirmAction,
  Input,
  KeyValueRow,
  LoadingState,
  Notice,
  ResultView,
  Screen,
  StatTile,
  StatTileRow,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { drainOutbox } from '@/sync/engine';
import { enqueueDailySummary } from '@/sync/ops';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

export default function DayCloseScreen() {
  const theme = useTheme();
  const pending = useOutboxStatus((state) => state.pending);
  const summary = useQuery({
    queryKey: ['agent', 'summary', 'today'],
    queryFn: getTodaySummary,
  });

  const [declaredCash, setDeclaredCash] = useState('');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedAmount, setSavedAmount] = useState<number | null>(null);
  const submittingRef = useRef(false);

  const declaredMinor = parseAmountToMinor(declaredCash);
  const expected = summary.data?.cash_in_hand ?? null;
  // Display-only preview; the authoritative variance is computed server-side.
  const difference = declaredMinor !== null && expected !== null ? declaredMinor - expected : null;
  const alreadySubmitted = summary.data && summary.data.summary.status !== 'open';

  async function save(minor: number) {
    if (submittingRef.current) {
      return;
    }
    submittingRef.current = true;
    setSubmitting(true);
    try {
      await enqueueDailySummary({ declared_cash: minor, notes: notes.trim() || undefined });
      setSavedAmount(minor);
      void drainOutbox();
    } catch {
      setError("Your declaration couldn't be saved on the phone. Nothing was submitted — please try again.");
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    setError(null);
    if (declaredMinor === null) {
      setError('Enter the cash you are holding, even if it is 0.00.');
      return;
    }
    confirmAction({
      title: 'Submit day summary?',
      message: `You are declaring ${formatMoney(declaredMinor)} cash in hand${
        difference !== null && difference !== 0
          ? `, which is ${formatMoney(Math.abs(difference))} ${difference > 0 ? 'more' : 'less'} than expected`
          : ''
      }. Your branch will reconcile this.`,
      confirmLabel: `Declare ${formatMoney(declaredMinor)}`,
      onConfirm: () => void save(declaredMinor),
    });
  }

  if (savedAmount !== null) {
    return (
      <Screen footer={<Button title="Back to home" size="lg" onPress={() => router.dismissTo('/(agent)')} />}>
        <ResultView
          tone="pending"
          title="Day summary saved"
          amount={formatMoney(savedAmount)}
          message="Saved on this phone and sent to your branch as soon as you're online. Hand over your cash as your branch instructs."
        />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <Button
          title={declaredMinor !== null ? `Declare ${formatMoney(declaredMinor)}` : 'Submit day summary'}
          icon="dayClose"
          size="lg"
          loading={submitting}
          loadingTitle="Saving…"
          onPress={handleSubmit}
        />
      }
    >
      {summary.isLoading ? (
        <LoadingState label="Loading today's figures…" />
      ) : summary.data ? (
        <>
          <StatTileRow>
            <StatTile
              icon="cash"
              label="Collected today"
              value={displayFormatted(summary.data.summary.collections_total_formatted)}
              hint={`${summary.data.summary.collections_count} collection${summary.data.summary.collections_count === 1 ? '' : 's'}`}
            />
            <StatTile icon="wallet" label="Expected cash in hand" value={formatMoney(summary.data.cash_in_hand)} />
          </StatTileRow>
          {alreadySubmitted ? (
            <View style={styles.statusRow}>
              <ThemedText type="small" themeColor="textSecondary">
                Today&apos;s summary
              </ThemedText>
              <Badge label={summary.data.summary.status} />
            </View>
          ) : null}
        </>
      ) : (
        <Notice
          tone="warning"
          title="Today's figures are unavailable"
          message="We couldn't reach the server. You can still declare your cash — it saves on this phone and syncs automatically."
        />
      )}

      {pending > 0 ? (
        <Notice
          tone="warning"
          title={`${pending} record${pending === 1 ? '' : 's'} not synced yet`}
          message="Collections still on this phone are not in the expected cash above. Sync first so your figures match."
        />
      ) : null}

      <Card style={styles.form}>
        <ThemedText type="heading">Declare your cash</ThemedText>
        <AmountInput
          label="Cash you are holding now"
          value={declaredCash}
          onChangeText={setDeclaredCash}
          error={error}
          hint="Count all the cash you collected today."
        />
        {difference !== null ? (
          <KeyValueRow
            label={difference === 0 ? 'Matches expected cash' : difference > 0 ? 'More than expected' : 'Less than expected'}
            value={difference === 0 ? '✓' : formatMoney(Math.abs(difference))}
            valueColor={difference === 0 ? theme.success : theme.warning}
            emphasis
          />
        ) : null}
        <Input
          label="Notes"
          optional
          placeholder="Explain any difference"
          value={notes}
          onChangeText={setNotes}
          multiline
        />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: Spacing.three },
  statusRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
});
