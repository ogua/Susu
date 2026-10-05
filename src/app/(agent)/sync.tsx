import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ThemedText } from '@/components/themed-text';
import { Badge, Button, Card, EmptyState, Icon, OfflineBanner, type IconName } from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { getDb } from '@/db/database';
import { useOnline } from '@/hooks/use-network';
import { useTheme } from '@/hooks/use-theme';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { drainOutbox } from '@/sync/engine';
import { retryRejected, type OutboxItem } from '@/sync/outbox';
import { formatDateTime, formatRelative } from '@/utils/format';
import { formatMoney } from '@/utils/money';

const OP_LABELS: Record<string, { label: string; icon: IconName }> = {
  'collection.record': { label: 'Cash collection', icon: 'cash' },
  'customer.register': { label: 'Customer registration', icon: 'personAdd' },
  'account.open': { label: 'Savings account opening', icon: 'wallet' },
  'summary.submit': { label: 'Day summary', icon: 'dayClose' },
  'loan.write_off': { label: 'Loan write-off', icon: 'loan' },
  'locations.record': { label: 'Route location update', icon: 'location' },
};

async function loadAllOps(): Promise<OutboxItem[]> {
  const db = await getDb();

  return db.getAllAsync<OutboxItem>('SELECT * FROM outbox ORDER BY created_at DESC LIMIT 200');
}

/** Human summary of a queued op from its own payload (amount, name). */
function describe(item: OutboxItem): string | null {
  try {
    const payload = JSON.parse(item.payload) as Record<string, unknown>;
    if (typeof payload.amount === 'number') return formatMoney(payload.amount);
    if (typeof payload.declared_cash === 'number') return `Declared ${formatMoney(payload.declared_cash)}`;
    if (typeof payload.first_name === 'string') return `${payload.first_name} ${String(payload.last_name ?? '')}`.trim();
    if (Array.isArray(payload.pings)) return `${payload.pings.length} point${payload.pings.length === 1 ? '' : 's'}`;
  } catch {
    // Malformed payloads still list, just without a summary.
  }

  return null;
}

function friendlyError(error: string | null): string {
  if (!error) return 'The server did not accept this record.';
  if (/network|timeout|ECONN|status code|exception|sql/i.test(error) || error.length > 200) {
    return 'The server could not process this record.';
  }

  return error;
}

export default function SyncQueueScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const online = useOnline();
  const lastSyncedAt = useOutboxStatus((state) => state.lastSyncedAt);
  const [items, setItems] = useState<OutboxItem[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [retrying, setRetrying] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setItems(await loadAllOps());
  }, []);

  useFocusEffect(
    useCallback(() => {
      void refresh();
    }, [refresh]),
  );

  async function handleSyncNow() {
    setRefreshing(true);
    await drainOutbox();
    await refresh();
    setRefreshing(false);
  }

  async function handleRetry(opId: string) {
    setRetrying(opId);
    await retryRejected(opId);
    await handleSyncNow();
    setRetrying(null);
  }

  const pending = items.filter((item) => item.status === 'pending').length;
  const rejected = items.filter((item) => item.status === 'rejected').length;
  // Location pings are background noise for the agent — keep them out of the list.
  const visible = items.filter((item) => item.op_type !== 'locations.record' || item.status !== 'synced');

  function renderItem({ item }: { item: OutboxItem }) {
    const meta = OP_LABELS[item.op_type] ?? { label: item.op_type, icon: 'info' as IconName };
    const detail = describe(item);
    const statusLabel = item.status === 'pending' ? 'Waiting to sync' : item.status === 'synced' ? 'Synced' : 'Needs attention';

    return (
      <View style={[styles.row, { borderBottomColor: theme.border }]}>
        <View style={[styles.icon, { backgroundColor: theme.surfaceMuted }]}>
          <Icon name={meta.icon} size={18} color={theme.textSecondary} />
        </View>
        <View style={styles.middle}>
          <ThemedText type="bodyStrong">{meta.label}</ThemedText>
          <ThemedText type="caption" themeColor="textMuted">
            {[detail, formatDateTime(item.recorded_at)].filter(Boolean).join(' · ')}
          </ThemedText>
          {item.status === 'rejected' ? (
            <ThemedText type="small" style={{ color: theme.danger }}>
              {friendlyError(item.last_error)}
            </ThemedText>
          ) : item.status === 'pending' && item.attempts > 0 ? (
            <ThemedText type="caption" themeColor="textMuted">
              Tried {item.attempts} time{item.attempts === 1 ? '' : 's'} · will retry automatically
            </ThemedText>
          ) : null}
        </View>
        {item.status === 'rejected' ? (
          <Button
            title="Retry"
            variant="secondary"
            loading={retrying === item.op_id}
            onPress={() => handleRetry(item.op_id)}
            accessibilityLabel={`Retry ${meta.label}`}
          />
        ) : (
          <Badge label={statusLabel} tone={item.status === 'synced' ? 'success' : 'warning'} />
        )}
      </View>
    );
  }

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={visible}
      keyExtractor={(item) => item.op_id}
      renderItem={renderItem}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleSyncNow} tintColor={theme.primary} />}
      ListHeaderComponent={
        <View style={styles.header}>
          <OfflineBanner mode="agent" />
          <Card style={styles.summary}>
            <View style={styles.summaryRow}>
              <SummaryNumber value={pending} label="Waiting" color={pending ? theme.warning : theme.textMuted} />
              <SummaryNumber value={rejected} label="Need attention" color={rejected ? theme.danger : theme.textMuted} />
            </View>
            <ThemedText type="caption" themeColor="textMuted">
              {lastSyncedAt ? `Last successful sync ${formatRelative(lastSyncedAt)}` : 'Records sync automatically whenever you are online.'}
            </ThemedText>
            <Button
              title={online ? 'Sync now' : 'Offline — will sync when connected'}
              icon="sync"
              loading={refreshing}
              loadingTitle="Syncing…"
              disabled={!online || pending === 0}
              onPress={handleSyncNow}
            />
          </Card>
        </View>
      }
      ListEmptyComponent={
        <EmptyState
          icon="cloudDone"
          title="Nothing waiting to sync"
          hint="Collections, registrations and day summaries you save offline appear here until the server confirms them."
        />
      }
    />
  );
}

function SummaryNumber({ value, label, color }: { value: number; label: string; color: string }) {
  return (
    <View style={styles.summaryItem} accessible accessibilityLabel={`${value} ${label}`}>
      <ThemedText type="moneyLarge" style={{ color }}>
        {value}
      </ThemedText>
      <ThemedText type="caption" themeColor="textSecondary">
        {label}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three, gap: Spacing.three },
  summary: { gap: Spacing.three },
  summaryRow: { flexDirection: 'row', gap: Spacing.three },
  summaryItem: { flex: 1 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: Spacing.three,
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  icon: { width: 36, height: 36, borderRadius: Radii.md, alignItems: 'center', justifyContent: 'center' },
  middle: { flex: 1, gap: 2 },
});
