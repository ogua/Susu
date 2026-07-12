import { router } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { useFocusEffect } from 'expo-router';

import { logout } from '@/api/auth';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';
import { drainOutbox } from '@/sync/engine';
import { pendingCount } from '@/sync/outbox';

/**
 * Phase 0 shell: session info + working sync-queue status.
 * Phase 1 replaces this with today's collection summary, account search,
 * and the duty toggle.
 */
export default function AgentDashboard() {
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const [pending, setPending] = useState(0);
  const [refreshing, setRefreshing] = useState(false);

  const refresh = useCallback(async () => {
    setPending(await pendingCount());
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

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Token may already be dead; local logout regardless.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  return (
    <ScrollView
      contentContainerStyle={styles.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleSyncNow} />}
    >
      <View style={styles.card}>
        <ThemedText type="subtitle">{user?.name}</ThemedText>
        <ThemedText type="small">{user?.role?.replaceAll('_', ' ')}</ThemedText>
        {user?.branches?.length ? (
          <ThemedText type="small">Branch: {user.branches[0].name}</ThemedText>
        ) : null}
      </View>

      <View style={styles.card}>
        <ThemedText type="subtitle">Sync queue</ThemedText>
        <ThemedText>
          {pending === 0 ? 'All caught up.' : `${pending} operation(s) waiting to sync.`}
        </ThemedText>
        <Pressable style={styles.button} onPress={handleSyncNow}>
          <ThemedText style={styles.buttonText}>Sync now</ThemedText>
        </Pressable>
      </View>

      <Pressable style={[styles.button, styles.logout]} onPress={handleLogout}>
        <ThemedText style={styles.buttonText}>Sign out</ThemedText>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 6,
    backgroundColor: '#ffffff',
  },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
    marginTop: 8,
  },
  logout: { backgroundColor: '#6b7280' },
  buttonText: { color: '#ffffff', fontWeight: '600' },
});
