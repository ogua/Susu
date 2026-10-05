import { useInfiniteQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams, useNavigation } from 'expo-router';
import { openBrowserAsync } from 'expo-web-browser';
import { useLayoutEffect, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getAccountTransactions, getCustomerStatementUrl } from '@/api/accounts';
import { apiErrorMessage } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import {
  Badge,
  Card,
  EmptyState,
  ErrorState,
  HeroCard,
  Icon,
  LoadingState,
  ProgressBar,
  SectionHeader,
  TransactionRow,
  type IconName,  SkeletonList,
} from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { SavingsProductType } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted } from '@/utils/money';

export default function AccountDetailScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const navigation = useNavigation();
  const params = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    productName?: string;
    productType?: SavingsProductType | '';
    balanceFormatted?: string;
    status?: string;
    targetAmountFormatted?: string;
    targetProgressPercent?: string;
    maturesAt?: string;
    maturedAt?: string;
    parValue?: string;
    parValueFormatted?: string;
    shareCount?: string;
  }>();
  const { accountId, productType, maturesAt, maturedAt } = params;

  const isFixedDeposit = productType === 'fixed_deposit';
  const isShares = productType === 'shares';
  const [downloadingStatement, setDownloadingStatement] = useState(false);

  useLayoutEffect(() => {
    navigation.setOptions({ title: params.productName || 'Account' });
  }, [navigation, params.productName]);

  const transactions = useInfiniteQuery({
    queryKey: ['customer', 'account', accountId, 'transactions'],
    queryFn: ({ pageParam }) => getAccountTransactions(accountId, pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    enabled: !!accountId,
  });

  const items = transactions.data?.pages.flatMap((page) => page.data) ?? [];

  async function handleDownloadStatement() {
    setDownloadingStatement(true);
    try {
      const url = await getCustomerStatementUrl(accountId);
      await openBrowserAsync(url);
    } catch (err) {
      Alert.alert("Couldn't open your statement", apiErrorMessage(err));
    } finally {
      setDownloadingStatement(false);
    }
  }

  const actions: { label: string; icon: IconName; onPress: () => void; busy?: boolean }[] = [
    {
      label: 'Deposit',
      icon: 'arrowDown',
      onPress: () => router.push({ pathname: '/(customer)/deposit', params: { accountId, accountNumber: params.accountNumber ?? '' } }),
    },
    {
      label: 'Withdraw',
      icon: 'arrowUp',
      onPress: () =>
        router.push({
          pathname: '/(customer)/withdraw',
          params: {
            accountId,
            accountNumber: params.accountNumber ?? '',
            balanceFormatted: params.balanceFormatted ?? '',
            productType: productType ?? '',
            maturesAt: maturesAt ?? '',
            maturedAt: maturedAt ?? '',
          },
        }),
    },
    ...(isShares
      ? [
          {
            label: 'Buy shares',
            icon: 'chart' as IconName,
            onPress: () =>
              router.push({
                pathname: '/(customer)/buy-shares',
                params: { accountId, parValue: params.parValue ?? '', parValueFormatted: params.parValueFormatted ?? '' },
              }),
          },
        ]
      : []),
    { label: 'Statement', icon: 'document', onPress: handleDownloadStatement, busy: downloadingStatement },
  ];

  const header = (
    <View style={styles.header}>
      <HeroCard
        label="Savings balance"
        amount={displayFormatted(params.balanceFormatted)}
        caption={`${params.accountNumber ?? ''}${params.productName ? ` · ${params.productName}` : ''}`}
      >
        {params.status && params.status !== 'active' ? <Badge label={params.status} /> : null}
      </HeroCard>

      <View style={styles.actions}>
        {actions.map((action) => (
          <Pressable
            key={action.label}
            accessibilityRole="button"
            accessibilityLabel={action.label}
            accessibilityState={{ busy: !!action.busy }}
            disabled={action.busy}
            onPress={action.onPress}
            style={({ pressed }) => [styles.action, pressed && { opacity: 0.7 }]}
          >
            <View style={[styles.actionIcon, { backgroundColor: theme.primarySoft }]}>
              {action.busy ? <ActivityIndicator color={theme.primaryText} /> : <Icon name={action.icon} size={22} color={theme.primaryText} />}
            </View>
            <ThemedText type="caption" style={styles.actionLabel}>
              {action.label}
            </ThemedText>
          </Pressable>
        ))}
      </View>

      {params.targetAmountFormatted ? (
        <Card>
          <View style={styles.rowBetween}>
            <ThemedText type="label">Target {displayFormatted(params.targetAmountFormatted)}</ThemedText>
            <ThemedText type="label" themeColor="primaryText">
              {params.targetProgressPercent || 0}%
            </ThemedText>
          </View>
          <ProgressBar
            progress={(Number(params.targetProgressPercent) || 0) / 100}
            accessibilityLabel={`${params.targetProgressPercent || 0}% of target saved`}
          />
          <ThemedText type="caption" themeColor="textMuted">
            {maturedAt
              ? 'Matured — you can withdraw without an early-withdrawal penalty.'
              : maturesAt
                ? `Matures ${formatDate(maturesAt)}. Early withdrawals may attract a penalty.`
                : ''}
          </ThemedText>
        </Card>
      ) : null}

      {isFixedDeposit ? (
        <Card style={styles.inline}>
          <Icon name={maturedAt ? 'checkCircle' : 'lock'} size={20} color={maturedAt ? theme.success : theme.warning} />
          <ThemedText type="small" style={styles.flex}>
            {maturedAt
              ? 'Matured — available to withdraw.'
              : maturesAt
                ? `Locked until ${formatDate(maturesAt)}. Fixed deposits can't be withdrawn before maturity.`
                : 'Fixed deposit'}
          </ThemedText>
        </Card>
      ) : null}

      {isShares ? (
        <Card style={styles.inline}>
          <Icon name="chart" size={20} color={theme.primaryText} />
          <ThemedText type="small" style={styles.flex}>
            You own <ThemedText type="label">{params.shareCount ?? '0'}</ThemedText> share{params.shareCount === '1' ? '' : 's'}
            {params.parValueFormatted ? ` at ${params.parValueFormatted} each` : ''}.
          </ThemedText>
        </Card>
      ) : null}

      <SectionHeader title="Transaction history" />
    </View>
  );

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={items}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => (
        <View style={{ backgroundColor: theme.surface }}>
          <TransactionRow transaction={item} />
        </View>
      )}
      ListHeaderComponent={header}
      onEndReachedThreshold={0.4}
      onEndReached={() => {
        if (transactions.hasNextPage && !transactions.isFetchingNextPage) {
          void transactions.fetchNextPage();
        }
      }}
      onRefresh={() => void transactions.refetch()}
      refreshing={transactions.isRefetching && !transactions.isFetchingNextPage}
      ListFooterComponent={transactions.isFetchingNextPage ? <LoadingState label="Loading more…" /> : null}
      ListEmptyComponent={
        transactions.isLoading ? (
          <SkeletonList />
        ) : transactions.isError ? (
          <ErrorState title="Couldn't load your transactions" onRetry={() => void transactions.refetch()} />
        ) : (
          <EmptyState
            icon="receipt"
            title="No transactions yet"
            hint="Deposits, withdrawals and charges on this account will be listed here."
          />
        )
      }
    />
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  header: { padding: Spacing.three, gap: Spacing.three },
  actions: { flexDirection: 'row', justifyContent: 'space-around' },
  action: { alignItems: 'center', gap: 6, minWidth: 72, paddingVertical: 4 },
  actionIcon: { width: 52, height: 52, borderRadius: Radii.pill, alignItems: 'center', justifyContent: 'center' },
  actionLabel: { fontWeight: '600' },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between' },
  inline: { flexDirection: 'row', alignItems: 'center', gap: 12 },
});
