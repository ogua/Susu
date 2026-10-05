import { useQuery } from '@tanstack/react-query';
import { router, useFocusEffect, type Href } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Switch, View } from 'react-native';
import { LineChart } from 'react-native-gifted-charts';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { logout } from '@/api/auth';
import { getAgentDashboard } from '@/api/dashboard';
import { setDuty } from '@/api/duty';
import { ThemedText } from '@/components/themed-text';
import {
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  HeroCard,
  HeroStat,
  Icon,
  IconButton,
  OfflineBanner,
  SectionHeader,
  SkeletonHero,
  SyncStatusPill,
  type IconName,
} from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { startBackgroundLocationTracking, stopBackgroundLocationTracking } from '@/location/backgroundTracking';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardCache } from '@/stores/dashboardCache';
import { useDutyStore } from '@/stores/dutyStore';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { drainOutbox } from '@/sync/engine';
import { firstName, formatRelative, greeting, roleLabel } from '@/utils/format';
import { formatMoney } from '@/utils/money';

const ACTIONS: { label: string; hint: string; icon: IconName; href: Href }[] = [
  { label: 'Register customer', hint: 'Works offline', icon: 'personAdd', href: '/(agent)/register-customer' },
  { label: 'Close my day', hint: 'Declare cash', icon: 'dayClose', href: '/(agent)/day-close' },
  { label: 'Loans', hint: 'Repayments', icon: 'loan', href: '/(agent)/loans' },
  { label: 'Susu groups', hint: 'Contributions', icon: 'group', href: '/(agent)/groups' },
  { label: 'Group loans', hint: 'Deposits & repay', icon: 'savings', href: '/(agent)/group-loans' },
  { label: 'Sync status', hint: 'Saved records', icon: 'sync', href: '/(agent)/sync' },
];

export default function AgentDashboard() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const onDuty = useDutyStore((state) => state.onDuty);
  const setOnDuty = useDutyStore((state) => state.setOnDuty);
  const cached = useDashboardCache((state) => state.agent);
  const cacheHydrated = useDashboardCache((state) => state.hydrated);
  const hydrateCache = useDashboardCache((state) => state.hydrate);
  const saveAgent = useDashboardCache((state) => state.saveAgent);
  const refreshOutbox = useOutboxStatus((state) => state.refresh);
  const [refreshing, setRefreshing] = useState(false);
  const [togglingDuty, setTogglingDuty] = useState(false);
  const [dutyError, setDutyError] = useState<string | null>(null);
  const [chartWidth, setChartWidth] = useState(0);

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

  useFocusEffect(
    useCallback(() => {
      void refreshOutbox();
    }, [refreshOutbox]),
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

  async function handleRefresh() {
    setRefreshing(true);
    await drainOutbox();
    await refreshOutbox();
    await dashboard.refetch();
    setRefreshing(false);
  }

  async function handleToggleDuty(value: boolean) {
    setTogglingDuty(true);
    setDutyError(null);
    try {
      const confirmed = await setDuty(value);
      setOnDuty(confirmed);
      if (confirmed) {
        await startBackgroundLocationTracking();
      } else {
        await stopBackgroundLocationTracking();
      }
    } catch {
      setDutyError('Changing duty status needs a connection. Try again when you are online.');
    } finally {
      setTogglingDuty(false);
    }
  }

  async function signOut() {
    await stopBackgroundLocationTracking();
    try {
      await logout();
    } catch {
      // Local logout regardless of server reachability.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  function handleLogout() {
    const { pending } = useOutboxStatus.getState();
    if (pending > 0) {
      Alert.alert(
        `${pending} record${pending === 1 ? '' : 's'} not synced yet`,
        'Connect to the internet and sync before signing out if you can. Unsynced records stay safely on this phone and are sent the next time you sign in — never under another user.',
        [
          { text: 'Stay signed in', style: 'cancel' },
          { text: 'Sign out anyway', style: 'destructive', onPress: () => void signOut() },
        ],
      );
      return;
    }
    Alert.alert('Sign out?', 'You will need your password to sign in again.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign out', style: 'destructive', onPress: () => void signOut() },
    ]);
  }

  const trend = data?.trend ?? [];
  const trendData = trend.map((point) => ({ value: point.total / 100 }));
  const trendTotal = trend.reduce((sum, point) => sum + point.total, 0);
  const hasTrend = trend.some((point) => point.total > 0);
  const branch = user?.branches?.[0]?.name;

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <ScrollView
        contentContainerStyle={[styles.content, { paddingTop: insets.top + Spacing.two, paddingBottom: insets.bottom + Spacing.five }]}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={handleRefresh} tintColor={theme.primary} />}
        showsVerticalScrollIndicator={false}
      >
        <View style={styles.header}>
          <Avatar name={user?.name ?? ''} size={44} />
          <View style={styles.flex}>
            <ThemedText type="small" themeColor="textSecondary">
              {greeting()},
            </ThemedText>
            <ThemedText type="heading" numberOfLines={1}>
              {firstName(user?.name)}
            </ThemedText>
            <ThemedText type="caption" themeColor="textMuted" numberOfLines={1}>
              {roleLabel(user?.role)}
              {branch ? ` · ${branch}` : ''}
            </ThemedText>
          </View>
          <IconButton icon="logout" label="Sign out" onPress={handleLogout} color={theme.textSecondary} />
        </View>

        <OfflineBanner mode="agent" />

        <Animated.View entering={FadeInDown.duration(260)}>
          {dashboard.isLoading && !data ? (
            <SkeletonHero />
          ) : data ? (
            <HeroCard
              label="Collected today"
              amount={formatMoney(data.today.collections_total)}
              caption={
                isStale && cached
                  ? `Offline · figures from ${formatRelative(cached.fetchedAt)}`
                  : 'Confirmed by the server · excludes records still on this phone'
              }
            >
              <View style={styles.heroStats}>
                <HeroStat label="Collections" value={String(data.today.collections_count)} />
                <HeroStat label="Cash in hand" value={formatMoney(data.today.expected_cash)} />
                <HeroStat label="Active accounts" value={String(data.accounts.active_count)} />
              </View>
            </HeroCard>
          ) : (
            <Card>
              <EmptyState
                icon="offline"
                title="Today's figures aren't available"
                hint="We couldn't reach the server and there's no saved copy yet. You can still record collections — they save on this phone."
                actionLabel="Try again"
                onAction={() => void dashboard.refetch()}
              />
            </Card>
          )}
        </Animated.View>

        <SyncStatusPill />

        <Button
          title="Record a collection"
          icon="cash"
          size="lg"
          onPress={() => router.push('/(agent)/accounts')}
          accessibilityHint="Find a customer account to collect from"
        />

        <Card style={styles.dutyCard}>
          <View style={[styles.dutyIcon, { backgroundColor: onDuty ? theme.successSoft : theme.surfaceMuted }]}>
            <Icon name="location" size={18} color={onDuty ? theme.success : theme.textMuted} />
          </View>
          <View style={styles.flex}>
            <ThemedText type="label">{onDuty ? 'On duty' : 'Off duty'}</ThemedText>
            <ThemedText type="caption" themeColor="textMuted">
              {onDuty ? 'Your route is shared with your branch.' : 'Location sharing is paused.'}
            </ThemedText>
          </View>
          {togglingDuty ? (
            <ActivityIndicator color={theme.primary} />
          ) : (
            <Switch
              value={onDuty}
              onValueChange={handleToggleDuty}
              accessibilityLabel="On duty"
              trackColor={{ true: theme.primary, false: theme.borderStrong }}
            />
          )}
        </Card>
        {dutyError ? (
          <ThemedText type="small" style={{ color: theme.danger }}>
            {dutyError}
          </ThemedText>
        ) : null}

        <SectionHeader title="Quick actions" />
        <View style={styles.grid}>
          {ACTIONS.map((action, index) => (
            <Animated.View
              key={action.label}
              entering={FadeInDown.duration(220).delay(40 * index)}
              style={styles.tileWrap}
            >
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={action.label}
                accessibilityHint={action.hint}
                onPress={() => router.push(action.href)}
                style={({ pressed }) => [
                  styles.tile,
                  { backgroundColor: pressed ? theme.surfaceMuted : theme.surface, borderColor: theme.border },
                ]}
              >
                <View style={[styles.tileIcon, { backgroundColor: theme.primarySoft }]}>
                  <Icon name={action.icon} size={20} color={theme.primaryText} />
                </View>
                <ThemedText type="label" numberOfLines={1}>
                  {action.label}
                </ThemedText>
                <ThemedText type="caption" themeColor="textMuted" numberOfLines={1}>
                  {action.hint}
                </ThemedText>
              </Pressable>
            </Animated.View>
          ))}
        </View>

        {data ? (
          <Card>
            <View style={styles.chartHeader}>
              <View style={styles.flex}>
                <ThemedText type="label">Collections · last 30 days</ThemedText>
                <ThemedText type="caption" themeColor="textMuted">
                  Total {formatMoney(trendTotal)}
                </ThemedText>
              </View>
              {data.today.summary_status ? <Badge label={data.today.summary_status} /> : null}
            </View>
            {hasTrend ? (
              <View onLayout={(event) => setChartWidth(event.nativeEvent.layout.width)} accessibilityLabel={`Collections trend chart, 30 day total ${formatMoney(trendTotal)}`}>
                {chartWidth > 0 ? (
                  <LineChart
                    data={trendData}
                    width={chartWidth - 40}
                    height={140}
                    thickness={2.5}
                    color={theme.chartLine}
                    hideDataPoints
                    areaChart
                    curved
                    startFillColor={theme.chartFill}
                    endFillColor={theme.surface}
                    startOpacity={0.9}
                    endOpacity={0.05}
                    yAxisTextStyle={{ color: theme.textMuted, fontSize: 10 }}
                    yAxisLabelPrefix="₵"
                    noOfSections={3}
                    rulesColor={theme.border}
                    rulesType="dashed"
                    xAxisThickness={0}
                    yAxisThickness={0}
                    initialSpacing={4}
                    adjustToWidth
                    disableScroll
                  />
                ) : null}
              </View>
            ) : (
              <ThemedText type="small" themeColor="textMuted" style={styles.chartEmpty}>
                No confirmed collections in the last 30 days yet.
              </ThemedText>
            )}
          </Card>
        ) : null}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  content: { paddingHorizontal: Spacing.three, gap: Spacing.three },
  header: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  heroStats: { flexDirection: 'row', gap: Spacing.two },
  dutyCard: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  dutyIcon: { width: 36, height: 36, borderRadius: Radii.pill, alignItems: 'center', justifyContent: 'center' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tileWrap: { flexBasis: '30%', flexGrow: 1, minWidth: 100 },
  tile: {
    borderRadius: Radii.lg,
    borderWidth: StyleSheet.hairlineWidth,
    padding: 12,
    gap: 4,
    minHeight: 104,
  },
  tileIcon: {
    width: 36,
    height: 36,
    borderRadius: Radii.md,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 4,
  },
  chartHeader: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  chartEmpty: { paddingVertical: Spacing.four, textAlign: 'center' },
});
