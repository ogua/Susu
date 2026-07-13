import { useQuery } from '@tanstack/react-query';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Switch, View } from 'react-native';

import { logout } from '@/api/auth';
import { setDuty } from '@/api/duty';
import { getTodaySummary } from '@/api/summaries';
import { ThemedText } from '@/components/themed-text';
import { startBackgroundLocationTracking, stopBackgroundLocationTracking } from '@/location/backgroundTracking';
import { useAuthStore } from '@/stores/authStore';
import { useDutyStore } from '@/stores/dutyStore';
import { drainOutbox } from '@/sync/engine';
import { pendingCount } from '@/sync/outbox';

export default function AgentDashboard() {
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const onDuty = useDutyStore((state) => state.onDuty);
  const setOnDuty = useDutyStore((state) => state.setOnDuty);
  const [pending, setPending] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const [togglingDuty, setTogglingDuty] = useState(false);

  const summary = useQuery({
    queryKey: ['agent', 'summary', 'today'],
    queryFn: getTodaySummary,
    staleTime: 30_000,
  });

  const refreshPending = useCallback(async () => {
    setPending(await pendingCount());
  }, []);

  useFocusEffect(
    useCallback(() => {
      void refreshPending();
    }, [refreshPending]),
  );

  useEffect(() => {
    // Reconciles native tracking with the "on duty" default after a fresh
    // app launch, where this JS state resets but the OS-level task may not
    // be running yet. Best-effort — never blocks the dashboard on this.
    if (onDuty) {
      void startBackgroundLocationTracking();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleSyncNow() {
    setRefreshing(true);
    await drainOutbox();
    await refreshPending();
    await summary.refetch();
    setRefreshing(false);
  }

  async function handleToggleDuty(value: boolean) {
    setTogglingDuty(true);
    try {
      const confirmed = await setDuty(value);
      setOnDuty(confirmed);
      if (confirmed) {
        await startBackgroundLocationTracking();
      } else {
        await stopBackgroundLocationTracking();
      }
    } catch {
      // Duty toggle needs connectivity; leave the switch as it was.
    } finally {
      setTogglingDuty(false);
    }
  }

  async function handleLogout() {
    await stopBackgroundLocationTracking();
    try {
      await logout();
    } catch {
      // Local logout regardless of server reachability.
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

        <View style={styles.dutyRow}>
          <ThemedText>{onDuty ? 'On duty' : 'Off duty'}</ThemedText>
          {togglingDuty ? (
            <ActivityIndicator />
          ) : (
            <Switch value={onDuty} onValueChange={handleToggleDuty} />
          )}
        </View>
      </View>

      <View style={styles.card}>
        <ThemedText type="subtitle">Today</ThemedText>
        {summary.isLoading ? (
          <ActivityIndicator />
        ) : summary.data ? (
          <>
            <ThemedText>{summary.data.summary.collections_count} collections recorded</ThemedText>
            <ThemedText>Total: {summary.data.summary.collections_total_formatted}</ThemedText>
            <ThemedText type="small">
              Cash in hand: GHS {(summary.data.cash_in_hand / 100).toFixed(2)}
            </ThemedText>
          </>
        ) : (
          <ThemedText type="small">Could not load today&apos;s summary (offline?).</ThemedText>
        )}
      </View>

      <View style={styles.grid}>
        <Pressable style={styles.tile} onPress={() => router.push('/(agent)/accounts')}>
          <ThemedText style={styles.tileText}>My Accounts</ThemedText>
        </Pressable>
        <Pressable style={styles.tile} onPress={() => router.push('/(agent)/register-customer')}>
          <ThemedText style={styles.tileText}>Register Customer</ThemedText>
        </Pressable>
        <Pressable style={styles.tile} onPress={() => router.push('/(agent)/day-close')}>
          <ThemedText style={styles.tileText}>Day Summary</ThemedText>
        </Pressable>
        <Pressable style={styles.tile} onPress={() => router.push('/(agent)/sync')}>
          <ThemedText style={styles.tileText}>
            Sync Queue{pending > 0 ? ` (${pending})` : ''}
          </ThemedText>
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
  dutyRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: 8,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 12,
  },
  tile: {
    flexBasis: '47%',
    flexGrow: 1,
    backgroundColor: '#eef4ff',
    borderRadius: 12,
    paddingVertical: 20,
    paddingHorizontal: 12,
    alignItems: 'center',
  },
  tileText: { fontWeight: '600', color: '#208AEF', textAlign: 'center' },
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
