import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { LineChart, PieChart } from 'react-native-gifted-charts';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getCustomerAccounts } from '@/api/accounts';
import { logout } from '@/api/auth';
import { getCustomerDashboard } from '@/api/dashboard';
import { ThemedText } from '@/components/themed-text';
import {
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  HeroCard,
  HeroStat,
  Icon,
  IconButton,
  LoadingState,
  OfflineBanner,
  PressableCard,
  ProgressBar,
  SectionHeader,
} from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardCache } from '@/stores/dashboardCache';
import type { SavingsAccount, SavingsProductType } from '@/types/api';
import { firstName, formatDate, formatRelative, greeting } from '@/utils/format';
import { displayFormatted, formatMoney } from '@/utils/money';

const PRODUCT_LABELS: Record<SavingsProductType, string> = {
  daily_susu: 'Daily susu',
  target: 'Target savings',
  fixed_deposit: 'Fixed deposit',
  shares: 'Shares',
};

export default function CustomerHome() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);
  const cached = useDashboardCache((state) => state.customer);
  const cacheHydrated = useDashboardCache((state) => state.hydrated);
  const hydrateCache = useDashboardCache((state) => state.hydrate);
  const saveCustomer = useDashboardCache((state) => state.saveCustomer);
  const [chartWidth, setChartWidth] = useState(0);

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
  const donutColors = [theme.chartLine, theme.success, theme.warning, theme.info, theme.neutral];

  function handleLogout() {
    Alert.alert('Sign out?', 'You will need your password to sign in again.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Sign out',
        style: 'destructive',
        onPress: async () => {
          try {
            await logout();
          } catch {
            // Local logout regardless of server reachability.
          }
          await clearSession();
          router.replace('/(auth)/login');
        },
      },
    ]);
  }

  function openAccount(account: SavingsAccount) {
    router.push({
      pathname: '/(customer)/account/[accountId]',
      params: {
        accountId: account.id,
        accountNumber: account.account_number,
        productName: account.product?.name ?? '',
        productType: account.product?.type ?? '',
        balanceFormatted: account.balance_formatted,
        status: account.status,
        targetAmountFormatted: account.target_amount_formatted ?? '',
        targetProgressPercent: String(account.target_progress_percent ?? ''),
        maturesAt: account.matures_at ?? '',
        maturedAt: account.matured_at ?? '',
        parValue: String(account.product?.par_value ?? ''),
        parValueFormatted: account.product?.par_value != null ? formatMoney(account.product.par_value) : '',
        shareCount: String(account.share_count ?? 0),
      },
    });
  }

  function renderAccount(account: SavingsAccount, index: number) {
    const type = account.product?.type;
    const isTarget = account.target_amount !== null;
    const isDaily = type === 'daily_susu' || (!type && !isTarget);
    const cycle = account.product?.cycle_length_days ?? 31;
    const progress = isTarget
      ? (account.target_progress_percent ?? 0) / 100
      : isDaily && account.contribution_amount
        ? account.contributions_this_cycle / cycle
        : null;
    const detail = isTarget
      ? `${account.target_progress_percent ?? 0}% of ${displayFormatted(account.target_amount_formatted)} target`
      : type === 'fixed_deposit'
        ? account.matured_at
          ? 'Matured · available to withdraw'
          : account.matures_at
            ? `Locked until ${formatDate(account.matures_at)}`
            : 'Fixed deposit'
        : type === 'shares'
          ? `${account.share_count} share${account.share_count === 1 ? '' : 's'} owned`
          : `${account.contributions_this_cycle} of ${cycle} days paid this cycle`;

    return (
      <Animated.View key={account.id} entering={FadeInDown.duration(220).delay(50 * index)}>
        <PressableCard
          onPress={() => openAccount(account)}
          accessibilityLabel={`${account.product?.name ?? 'Savings account'} ${account.account_number}, savings balance ${displayFormatted(account.balance_formatted)}. ${detail}`}
          accessibilityHint="Opens account details and history"
        >
          <View style={styles.accountHeader}>
            <View style={[styles.accountIcon, { backgroundColor: theme.primarySoft }]}>
              <Icon name={type === 'shares' ? 'chart' : type === 'fixed_deposit' ? 'lock' : 'savings'} size={18} color={theme.primaryText} />
            </View>
            <View style={styles.flex}>
              <ThemedText type="label">{account.product?.name ?? (type ? PRODUCT_LABELS[type] : 'Savings')}</ThemedText>
              <ThemedText type="caption" themeColor="textMuted">
                {account.account_number}
              </ThemedText>
            </View>
            {account.status !== 'active' ? <Badge label={account.status} /> : <Icon name="chevronRight" size={18} color={theme.textMuted} />}
          </View>
          <ThemedText type="caption" themeColor="textSecondary">
            Savings balance
          </ThemedText>
          <ThemedText type="moneyLarge">{displayFormatted(account.balance_formatted)}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {detail}
          </ThemedText>
          {progress !== null ? <ProgressBar progress={progress} accessibilityLabel={detail} /> : null}
        </PressableCard>
      </Animated.View>
    );
  }

  const trend = summary?.balance_trend ?? [];
  const trendData = trend.map((point) => ({ value: point.balance / 100 }));
  const donutAccounts = (summary?.accounts ?? []).filter((account) => account.balance > 0);
  const donutData = donutAccounts.map((account, index) => ({
    value: account.balance,
    color: donutColors[index % donutColors.length],
  }));

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <ScrollView
        contentContainerStyle={[styles.content, { paddingTop: insets.top + Spacing.two, paddingBottom: insets.bottom + Spacing.five }]}
        refreshControl={
          <RefreshControl
            refreshing={accounts.isRefetching || dashboard.isRefetching}
            tintColor={theme.primary}
            onRefresh={() => {
              void accounts.refetch();
              void dashboard.refetch();
            }}
          />
        }
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
          </View>
          <IconButton icon="logout" label="Sign out" onPress={handleLogout} color={theme.textSecondary} />
        </View>

        <OfflineBanner mode="customer" />

        <Animated.View entering={FadeInDown.duration(260)}>
          {summary ? (
            <HeroCard
              label="Total savings across your accounts"
              amount={formatMoney(summary.totals.balance)}
              caption={isStale && cached ? `Offline · last updated ${formatRelative(cached.fetchedAt)}` : undefined}
            >
              <View style={styles.heroStats}>
                <HeroStat label="Accounts" value={String(summary.accounts.length)} />
                <HeroStat
                  label={summary.loans.active_count > 0 ? `Loan balance owed (${summary.loans.active_count})` : 'Loans'}
                  value={summary.loans.active_count > 0 ? formatMoney(summary.loans.outstanding) : 'None'}
                />
              </View>
            </HeroCard>
          ) : dashboard.isLoading ? (
            <Card style={styles.heroPlaceholder}>
              <LoadingState label="Loading your savings…" />
            </Card>
          ) : null}
        </Animated.View>

        <View style={styles.actions}>
          <Button title="My loans" icon="loan" variant="secondary" style={styles.flex} onPress={() => router.push('/(customer)/loans')} />
          <Button title="Susu groups" icon="group" variant="secondary" style={styles.flex} onPress={() => router.push('/(customer)/groups')} />
        </View>

        <SectionHeader title="Your accounts" />
        {accounts.isLoading ? (
          <LoadingState label="Loading accounts…" />
        ) : accounts.isError ? (
          <ErrorState title="Couldn't load your accounts" onRetry={() => void accounts.refetch()} />
        ) : accounts.data && accounts.data.length > 0 ? (
          <View style={styles.accounts}>{accounts.data.map(renderAccount)}</View>
        ) : (
          <EmptyState
            icon="wallet"
            title="No savings accounts yet"
            hint="Your susu agent or branch can open one for you. It will appear here straight away."
          />
        )}

        {summary && trendData.length > 1 ? (
          <Card>
            <ThemedText type="label">Savings balance · last 30 days</ThemedText>
            <View onLayout={(event) => setChartWidth(event.nativeEvent.layout.width)} accessibilityLabel="Savings balance trend chart">
              {chartWidth > 0 ? (
                <LineChart
                  data={trendData}
                  width={chartWidth - 40}
                  height={130}
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
          </Card>
        ) : null}

        {donutData.length > 1 ? (
          <Card>
            <ThemedText type="label">How your savings are split</ThemedText>
            <View style={styles.donutRow}>
              <PieChart data={donutData} donut radius={52} innerRadius={34} innerCircleColor={theme.surface} />
              <View style={styles.legend}>
                {donutAccounts.map((account, index) => (
                  <View key={account.id} style={styles.legendRow} accessible accessibilityLabel={`${account.product ?? account.account_number}: ${formatMoney(account.balance)}`}>
                    <View style={[styles.legendDot, { backgroundColor: donutColors[index % donutColors.length] }]} />
                    <View style={styles.flex}>
                      <ThemedText type="caption" numberOfLines={1}>
                        {account.product ?? account.account_number}
                      </ThemedText>
                      <ThemedText type="caption" themeColor="textMuted">
                        {formatMoney(account.balance)}
                      </ThemedText>
                    </View>
                  </View>
                ))}
              </View>
            </View>
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
  heroPlaceholder: { minHeight: 160, justifyContent: 'center' },
  heroStats: { flexDirection: 'row', gap: Spacing.two },
  actions: { flexDirection: 'row', gap: 12 },
  accounts: { gap: 12 },
  accountHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 4 },
  accountIcon: { width: 36, height: 36, borderRadius: Radii.md, alignItems: 'center', justifyContent: 'center' },
  donutRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  legend: { flex: 1, gap: 8 },
  legendRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  legendDot: { width: 10, height: 10, borderRadius: 5 },
});
