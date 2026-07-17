import { useQuery } from '@tanstack/react-query';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, Switch, View } from 'react-native';
import { LineChart } from 'react-native-gifted-charts';

import { logout } from '@/api/auth';
import { getAgentDashboard } from '@/api/dashboard';
import { setDuty } from '@/api/duty';
import { ThemedText } from '@/components/themed-text';
import { Badge, Button, Card, Screen, StatTile, StatTileRow } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { startBackgroundLocationTracking, stopBackgroundLocationTracking } from '@/location/backgroundTracking';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardCache } from '@/stores/dashboardCache';
import { useDutyStore } from '@/stores/dutyStore';
import { drainOutbox } from '@/sync/engine';
import { pendingCount } from '@/sync/outbox';
import { formatMoney } from '@/utils/money';

const NAV_TILES = [
  { label: 'My Accounts', href: '/(agent)/accounts' },
  { label: 'Register Customer', href: '/(agent)/register-customer' },
  { label: 'Day Summary', href: '/(agent)/day-close' },
  { label: 'Loans', href: '/(agent)/loans' },
  { label: 'Susu Groups', href: '/(agent)/groups/index' },
  { label: 'Sync Queue', href: '/(agent)/sync' },
] as const;

export default function AgentDashboard() {
  const theme = useTheme();
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const onDuty = useDutyStore((state) => state.onDuty);
  const setOnDuty = useDutyStore((state) => state.setOnDuty);
  const cached = useDashboardCache((state) => state.agent);
  const cacheHydrated = useDashboardCache((state) => state.hydrated);
  const hydrateCache = useDashboardCache((state) => state.hydrate);
  const saveAgent = useDashboardCache((state) => state.saveAgent);
  const [pending, setPending] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const [togglingDuty, setTogglingDuty] = useState(false);

  const dashboard = useQuery({
    queryKey: ['agent', 'dashboard'],
    queryFn: async () => {
      const data = await getAgentDashboard();
      void saveAgent(data);
      return data;
    },
    staleTime: 30_000,
  });

  // Live payload when online; last-synced snapshot when in the field offline.
  const data = dashboard.data ?? cached?.data;
  const isStale = !dashboard.data && !!cached;

  const refreshPending = useCallback(async () => {
    setPending(await pendingCount());
  }, []);

  useFocusEffect(
    useCallback(() => {
      void refreshPending();
    }, [refreshPending]),
  );

  useEffect(() => {
    if (!cacheHydrated) {
      void hydrateCache();
    }
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
    await dashboard.refetch();
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

  const trendData = (data?.trend ?? []).map((point) => ({
    value: point.total / 100,
  }));

  return (
    <Screen refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleSyncNow} />}>
      <Card style={styles.profileCard}>
        <View style={styles.profileRow}>
          <View style={styles.profileText}>
            <ThemedText type="subtitle">{user?.name}</ThemedText>
            <ThemedText type="small" themeColor="textSecondary">
              {user?.role?.replaceAll('_', ' ')}
              {user?.branches?.length ? ` · ${user.branches[0].name}` : ''}
            </ThemedText>
          </View>
          <View style={styles.dutyToggle}>
            <Badge label={onDuty ? 'On duty' : 'Off duty'} tone={onDuty ? 'success' : 'neutral'} />
            {togglingDuty ? (
              <ActivityIndicator />
            ) : (
              <Switch
                value={onDuty}
                onValueChange={handleToggleDuty}
                trackColor={{ true: Palette.primary500 }}
              />
            )}
          </View>
        </View>
      </Card>

      {dashboard.isLoading && !data ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : data ? (
        <>
          <StatTileRow>
            <StatTile
              label="Collected today"
              value={formatMoney(data.today.collections_total)}
              hint={`${data.today.collections_count} collection(s)`}
              accentColor={Palette.success}
            />
            <StatTile
              label="Cash in hand"
              value={formatMoney(data.today.expected_cash)}
              hint={`${data.accounts.active_count} active account(s)`}
            />
          </StatTileRow>

          <Card>
            <ThemedText type="smallBold" themeColor="textSecondary">
              COLLECTIONS — LAST 30 DAYS
            </ThemedText>
            {isStale && cached ? (
              <ThemedText type="small" themeColor="textSecondary">
                Offline — last updated {new Date(cached.fetchedAt).toLocaleString()}
              </ThemedText>
            ) : null}
            <LineChart
              data={trendData}
              height={140}
              thickness={2.5}
              color={Palette.primary500}
              hideDataPoints
              areaChart
              startFillColor={Palette.primary100}
              endFillColor="#ffffff00"
              startOpacity={0.9}
              endOpacity={0.05}
              yAxisTextStyle={{ color: theme.textSecondary, fontSize: 10 }}
              hideRules
              xAxisThickness={0}
              yAxisThickness={0}
              initialSpacing={4}
              adjustToWidth
              disableScroll
            />
          </Card>
        </>
      ) : (
        <Card>
          <ThemedText type="small" themeColor="textSecondary">
            Could not load today&apos;s numbers (offline?). Pull down to retry — your queued
            collections still sync automatically.
          </ThemedText>
        </Card>
      )}

      <View style={styles.grid}>
        {NAV_TILES.map((tile) => (
          <Pressable
            key={tile.label}
            style={(state) => [
              styles.tile,
              { backgroundColor: theme.primarySoft },
              state.pressed && styles.tilePressed,
            ]}
            onPress={() => router.push(tile.href)}
          >
            <ThemedText style={[styles.tileText, { color: Palette.primary600 }]}>
              {tile.label}
              {tile.label === 'Sync Queue' && pending > 0 ? ` (${pending})` : ''}
            </ThemedText>
          </Pressable>
        ))}
      </View>

      <Button title="Sign out" variant="ghost" onPress={handleLogout} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  profileCard: { gap: 10 },
  profileRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  profileText: { flex: 1, gap: 2 },
  dutyToggle: { alignItems: 'flex-end', gap: 6 },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 12,
  },
  tile: {
    flexBasis: '47%',
    flexGrow: 1,
    borderRadius: Radii.md,
    paddingVertical: 20,
    paddingHorizontal: 12,
    alignItems: 'center',
  },
  tilePressed: { opacity: 0.7 },
  tileText: { fontWeight: '600', textAlign: 'center' },
});
