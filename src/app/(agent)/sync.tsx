import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { drainOutbox } from '@/sync/engine';
import { retryRejected, type OutboxItem } from '@/sync/outbox';
import { getDb } from '@/db/database';

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
    const statusColor =
      item.status === 'synced' ? '#1a8a3d' : item.status === 'rejected' ? '#d11a2a' : '#b45309';

    return (
      <View style={styles.row}>
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">{item.op_type}</ThemedText>
          <ThemedText type="small" style={{ color: statusColor }}>
            {item.status}
            {item.last_error ? ` — ${item.last_error}` : ''}
          </ThemedText>
        </View>
        {item.status === 'rejected' ? (
          <Pressable style={styles.retryButton} onPress={() => handleRetry(item.op_id)}>
            <ThemedText type="small" style={{ color: '#ffffff' }}>
              Retry
            </ThemedText>
          </Pressable>
        ) : null}
      </View>
    );
  }

  return (
    <FlatList
      contentContainerStyle={styles.container}
      data={items}
      keyExtractor={(item) => item.op_id}
      renderItem={renderItem}
      ItemSeparatorComponent={() => <View style={styles.separator} />}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleSyncNow} />}
      ListEmptyComponent={<ThemedText style={styles.empty}>Nothing queued.</ThemedText>}
    />
  );
}

const styles = StyleSheet.create({
  container: { padding: 16 },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, gap: 12 },
  separator: { height: 1, backgroundColor: '#e5e5ea' },
  retryButton: {
    backgroundColor: '#208AEF',
    borderRadius: 8,
    paddingVertical: 8,
    paddingHorizontal: 14,
  },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
