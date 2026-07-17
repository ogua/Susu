import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import { LineChart, PieChart } from 'react-native-gifted-charts';

import { getCustomerAccounts } from '@/api/accounts';
import { logout } from '@/api/auth';
import { getCustomerDashboard } from '@/api/dashboard';
import { ThemedText } from '@/components/themed-text';
import { Badge, Button, Card, EmptyState, ProgressBar, Screen } from '@/components/ui';
import { Palette } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardCache } from '@/stores/dashboardCache';
import type { SavingsAccount } from '@/types/api';
import { formatMoney } from '@/utils/money';

const DONUT_COLORS = [Palette.primary500, Palette.success, Palette.warning, Palette.primary700, Palette.neutral];

export default function CustomerHome() {
  const theme = useTheme();
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const cached = useDashboardCache((state) => state.customer);
  const cacheHydrated = useDashboardCache((state) => state.hydrated);
  const hydrateCache = useDashboardCache((state) => state.hydrate);
  const saveCustomer = useDashboardCache((state) => state.saveCustomer);

  const accounts = useQuery({
    queryKey: ['customer', 'accounts'],
    queryFn: getCustomerAccounts,
  });

  const dashboard = useQuery({
    queryKey: ['customer', 'dashboard'],
    queryFn: async () => {
      const data = await getCustomerDashboard();
      void saveCustomer(data);
      return data;
    },
    staleTime: 30_000,
  });

  useEffect(() => {
    if (!cacheHydrated) {
      void hydrateCache();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const summary = dashboard.data ?? cached?.data;
  const isStale = !dashboard.data && !!cached;

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Local logout regardless of server reachability.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  function renderAccount(account: SavingsAccount) {
    const isTarget = account.target_amount !== null;
    const progress = isTarget
      ? (account.target_progress_percent ?? 0) / 100
      : account.contribution_amount
        ? account.contributions_this_cycle / (account.product?.cycle_length_days ?? 31)
        : 0;

    return (
      <Pressable
        key={account.id}
        onPress={() =>
          router.push({
            pathname: '/(customer)/account/[accountId]',
            params: {
              accountId: account.id,
              targetAmountFormatted: account.target_amount_formatted ?? '',
              targetProgressPercent: String(account.target_progress_percent ?? ''),
              maturesAt: account.matures_at ?? '',
              maturedAt: account.matured_at ?? '',
            },
          })
        }
      >
        {(state) => (
          <Card style={state.pressed ? styles.pressed : undefined}>
            <View style={styles.accountHeader}>
              <ThemedText type="smallBold" themeColor="textSecondary">
                {account.account_number}
              </ThemedText>
              <Badge label={account.status} />
            </View>
            <ThemedText type="subtitle">{account.balance_formatted}</ThemedText>
            {isTarget ? (
              <ThemedText type="small" themeColor="textSecondary">
                {account.target_progress_percent}% of {account.target_amount_formatted} target
                {account.matured_at ? ' · Matured' : account.matures_at ? ` · Matures ${new Date(account.matures_at).toLocaleDateString()}` : ''}
              </ThemedText>
            ) : (
              <ThemedText type="small" themeColor="textSecondary">
                {account.contributions_this_cycle}/{account.product?.cycle_length_days ?? 31} days this cycle
              </ThemedText>
            )}
            <ProgressBar progress={progress} />
          </Card>
        )}
      </Pressable>
    );
  }

  const trendData = (summary?.balance_trend ?? []).map((point) => ({ value: point.balance / 100 }));
  const donutData = (summary?.accounts ?? []).map((account, index) => ({
    value: Math.max(account.balance, 1),
    color: DONUT_COLORS[index % DONUT_COLORS.length],
  }));

  return (
    <Screen
      refreshControl={
        <RefreshControl
          refreshing={accounts.isRefetching}
          onRefresh={() => {
            void accounts.refetch();
            void dashboard.refetch();
          }}
        />
      }
    >
      <Card>
        <ThemedText type="small" themeColor="textSecondary">
          Welcome back
        </ThemedText>
        <ThemedText type="subtitle">{user?.name}</ThemedText>
        {summary ? (
          <>
            <ThemedText type="title" style={{ color: Palette.primary600 }}>
              {formatMoney(summary.totals.balance)}
            </ThemedText>
            <ThemedText type="small" themeColor="textSecondary">
              Total savings
              {summary.loans.active_count > 0
                ? ` · ${summary.loans.active_count} active loan(s), ${formatMoney(summary.loans.outstanding)} outstanding`
                : ''}
              {isStale && cached ? ` · offline, updated ${new Date(cached.fetchedAt).toLocaleDateString()}` : ''}
            </ThemedText>
          </>
        ) : null}
      </Card>

      {summary && trendData.length > 0 ? (
        <Card>
          <View style={styles.chartRow}>
            <View style={styles.trendChart}>
              <ThemedText type="smallBold" themeColor="textSecondary">
                BALANCE — 30 DAYS
              </ThemedText>
              <LineChart
                data={trendData}
                height={110}
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
            </View>
            {donutData.length > 1 ? (
              <View style={styles.donut}>
                <ThemedText type="smallBold" themeColor="textSecondary">
                  BY ACCOUNT
                </ThemedText>
                <PieChart data={donutData} donut radius={48} innerRadius={30} />
              </View>
            ) : null}
          </View>
        </Card>
      ) : null}

      <View style={styles.navRow}>
        <View style={styles.navButton}>
          <Button title="My Loans" variant="secondary" onPress={() => router.push('/(customer)/loans')} />
        </View>
        <View style={styles.navButton}>
          <Button
            title="My Susu Groups"
            variant="secondary"
            onPress={() => router.push('/(customer)/groups/index')}
          />
        </View>
      </View>

      {accounts.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : accounts.isError ? (
        <EmptyState title="Could not load your accounts" hint="Pull down to retry." />
      ) : accounts.data && accounts.data.length > 0 ? (
        accounts.data.map(renderAccount)
      ) : (
        <EmptyState title="No savings accounts yet" hint="Ask your agent to open one for you." />
      )}

      <Button title="Sign out" variant="ghost" onPress={handleLogout} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  accountHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  pressed: { opacity: 0.85 },
  chartRow: { flexDirection: 'row', gap: 12, alignItems: 'flex-end' },
  trendChart: { flex: 1, gap: 6 },
  donut: { alignItems: 'center', gap: 6 },
  navRow: { flexDirection: 'row', gap: 12 },
  navButton: { flex: 1 },
});
