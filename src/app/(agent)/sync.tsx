import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Badge, EmptyState, ListRow, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { getDb } from '@/db/database';
import { drainOutbox } from '@/sync/engine';
import { retryRejected, type OutboxItem } from '@/sync/outbox';

async function loadAllOps(): Promise<OutboxItem[]> {
  const db = await getDb();

  return db.getAllAsync<OutboxItem>('SELECT * FROM outbox ORDER BY created_at DESC LIMIT 200');
}

export default function SyncQueueScreen() {
  const [items, setItems] = useState<OutboxItem[]>([]);
  const [refreshing, setRefreshing] = useState(false);

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
    await retryRejected(opId);
    await handleSyncNow();
  }

  function renderItem({ item }: { item: OutboxItem }) {
    return (
      <ListRow
        title={item.op_type.replaceAll('.', ' · ')}
        subtitle={item.last_error ?? undefined}
        right={
          item.status === 'rejected' ? (
            <Pressable style={styles.retryButton} onPress={() => handleRetry(item.op_id)}>
              <ThemedText type="smallBold" style={styles.retryText}>
                Retry
              </ThemedText>
            </Pressable>
          ) : (
            <Badge label={item.status} />
          )
        }
      />
    );
  }

  return (
    <Screen scroll={false}>
      <FlatList
        data={items}
        keyExtractor={(item) => item.op_id}
        renderItem={renderItem}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleSyncNow} />}
        ListEmptyComponent={
          <EmptyState title="Nothing queued" hint="Everything you record offline shows up here until it syncs." />
        }
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  retryButton: {
    backgroundColor: Palette.primary500,
    borderRadius: Radii.sm,
    paddingVertical: 8,
    paddingHorizontal: 14,
  },
  retryText: { color: '#ffffff' },
});
