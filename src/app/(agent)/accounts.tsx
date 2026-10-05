import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, StyleSheet, View } from 'react-native';

import { getAgentAccounts } from '@/api/accounts';
import { ThemedText } from '@/components/themed-text';
import { Avatar, Badge, EmptyState, ErrorState, Input, ListRow, LoadingState, OfflineBanner } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { SavingsAccount } from '@/types/api';
import { customerDisplayName } from '@/utils/customer';
import { displayFormatted } from '@/utils/money';

const SEARCH_DEBOUNCE_MS = 300;

/** Collector's customer finder: search by name, account number or phone, tap to collect. */
export default function AgentAccountsScreen() {
  const theme = useTheme();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [search]);

  const accounts = useQuery({
    queryKey: ['agent', 'accounts', debounced],
    queryFn: () => getAgentAccounts(debounced || undefined),
    placeholderData: keepPreviousData,
  });

  const items = accounts.data?.data ?? [];
  const searching = search.trim() !== debounced || (accounts.isFetching && !accounts.isRefetching);

  function openCollect(item: SavingsAccount) {
    router.push({
      pathname: '/(agent)/collect/[accountId]',
      params: {
        accountId: item.id,
        accountNumber: item.account_number,
        customerName: customerDisplayName(item.customer),
        customerId: item.customer_id,
        customerPhone: item.customer?.phone ?? '',
        productName: item.product?.name ?? '',
        contributionAmount: String(item.contribution_amount),
        balanceFormatted: item.balance_formatted,
        status: item.status,
      },
    });
  }

  function renderItem({ item }: { item: SavingsAccount }) {
    const name = customerDisplayName(item.customer) || item.account_number;
    const product = item.product?.name;
    const target =
      item.target_amount !== null && item.target_progress_percent !== null
        ? ` · ${item.target_progress_percent}% of target`
        : '';

    return (
      <ListRow
        left={<Avatar name={name} />}
        title={name}
        subtitle={`${item.account_number}${product ? ` · ${product}` : ''}${target}`}
        valueLabel="Savings balance"
        value={displayFormatted(item.balance_formatted)}
        subvalue={item.status !== 'active' ? undefined : `Agreed ${displayFormatted(item.contribution_formatted)}`}
        right={
          item.status !== 'active' ? (
            <View style={styles.right}>
              <ThemedText type="money">{displayFormatted(item.balance_formatted)}</ThemedText>
              <Badge label={item.status} />
            </View>
          ) : undefined
        }
        onPress={() => openCollect(item)}
        accessibilityLabel={`${name}, account ${item.account_number}, savings balance ${displayFormatted(item.balance_formatted)}${item.status !== 'active' ? `, ${item.status}` : ''}. Record collection.`}
      />
    );
  }

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <View style={styles.searchBar}>
        <Input
          icon="search"
          placeholder="Name, account number or phone"
          value={search}
          onChangeText={setSearch}
          autoCapitalize="none"
          autoCorrect={false}
          returnKeyType="search"
          clearButtonMode="while-editing"
          accessibilityLabel="Search customers"
        />
        <OfflineBanner mode="agent" />
        <View style={styles.metaRow}>
          <ThemedText type="caption" themeColor="textMuted">
            {accounts.data
              ? `${accounts.data.meta.total} account${accounts.data.meta.total === 1 ? '' : 's'}${debounced ? ` matching “${debounced}”` : ' assigned to you'}`
              : ' '}
          </ThemedText>
          {searching ? <ActivityIndicator size="small" color={theme.primary} /> : null}
        </View>
      </View>

      {accounts.isLoading ? (
        <LoadingState label="Loading your accounts…" />
      ) : accounts.isError && !accounts.data ? (
        <ErrorState
          title="Couldn't load accounts"
          hint="Searching needs a connection. Check your internet and try again."
          onRetry={() => void accounts.refetch()}
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          onRefresh={() => void accounts.refetch()}
          refreshing={accounts.isRefetching}
          initialNumToRender={12}
          windowSize={7}
          style={[styles.list, { backgroundColor: theme.surface, borderColor: theme.border }]}
          contentContainerStyle={items.length === 0 ? styles.emptyContainer : undefined}
          ListEmptyComponent={
            debounced ? (
              <EmptyState
                icon="search"
                title={`No accounts match “${debounced}”`}
                hint="Check the spelling, or search by account number or phone. New customers need registering first."
                actionLabel="Register a customer"
                onAction={() => router.push('/(agent)/register-customer')}
              />
            ) : (
              <EmptyState
                icon="wallet"
                title="No accounts assigned yet"
                hint="Register a customer and open a savings account to start collecting."
                actionLabel="Register a customer"
                onAction={() => router.push('/(agent)/register-customer')}
              />
            )
          }
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  searchBar: { paddingHorizontal: Spacing.three, paddingTop: Spacing.two, gap: Spacing.two },
  metaRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', minHeight: 20 },
  list: { flex: 1, borderTopWidth: StyleSheet.hairlineWidth, marginTop: Spacing.two },
  emptyContainer: { flexGrow: 1 },
  right: { alignItems: 'flex-end', gap: 4 },
});
