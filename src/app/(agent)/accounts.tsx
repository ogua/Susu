import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { openBrowserAsync } from 'expo-web-browser';
import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getAgentAccounts, getAgentStatementUrl } from '@/api/accounts';
import { apiErrorMessage } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import { EmptyState, Input, ListRow, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { SavingsAccount } from '@/types/api';

export default function AgentAccountsScreen() {
  const theme = useTheme();
  const [search, setSearch] = useState('');
  const [statementAccountId, setStatementAccountId] = useState<string | null>(null);

  const accounts = useQuery({
    queryKey: ['agent', 'accounts', search],
    queryFn: () => getAgentAccounts(search || undefined),
  });

  async function handleDownloadStatement(accountId: string) {
    setStatementAccountId(accountId);
    try {
      const url = await getAgentStatementUrl(accountId);
      await openBrowserAsync(url);
    } catch (err) {
      Alert.alert('Could not open statement', apiErrorMessage(err));
    } finally {
      setStatementAccountId(null);
    }
  }

  function renderItem({ item }: { item: SavingsAccount }) {
    const customerName = item.customer ? `${item.customer.first_name} ${item.customer.last_name}` : '';

    return (
      <ListRow
        title={customerName || item.account_number}
        subtitle={`${item.account_number}${
          item.target_amount !== null
            ? ` · ${item.target_progress_percent}% of ${item.target_amount_formatted} target`
            : ''
        }`}
        onPress={() =>
          router.push({
            pathname: '/(agent)/collect/[accountId]',
            params: {
              accountId: item.id,
              accountNumber: item.account_number,
              customerName,
              contributionAmount: String(item.contribution_amount),
              balanceFormatted: item.balance_formatted,
            },
          })
        }
        right={
          <View style={styles.rowRight}>
            <View style={styles.rowValue}>
              <ThemedText>{item.balance_formatted}</ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                {item.status}
              </ThemedText>
            </View>
            <Pressable
              style={[styles.pillButton, { borderColor: Palette.primary500 }]}
              onPress={() =>
                router.push({
                  pathname: '/(agent)/loans/apply/[accountId]',
                  params: { accountId: item.id, customerId: item.customer_id, customerName },
                })
              }
            >
              <ThemedText type="smallBold" style={{ color: Palette.primary600 }}>
                Loan
              </ThemedText>
            </Pressable>
            <Pressable
              style={[styles.pillButton, { borderColor: theme.border }]}
              disabled={statementAccountId === item.id}
              onPress={() => handleDownloadStatement(item.id)}
            >
              <ThemedText type="smallBold" themeColor="textSecondary">
                {statementAccountId === item.id ? 'Opening…' : 'Statement'}
              </ThemedText>
            </Pressable>
          </View>
        }
      />
    );
  }

  return (
    <Screen scroll={false}>
      <Input
        placeholder="Search by name, account, or phone"
        value={search}
        onChangeText={setSearch}
        autoCapitalize="none"
      />

      {accounts.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : accounts.isError ? (
        <EmptyState
          title="Could not load accounts"
          hint="Check your connection and pull to retry."
        />
      ) : (
        <FlatList
          data={accounts.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          onRefresh={() => accounts.refetch()}
          refreshing={accounts.isRefetching}
          ListEmptyComponent={<EmptyState title="No accounts found" />}
        />
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  rowRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  rowValue: { alignItems: 'flex-end', gap: 2 },
  pillButton: {
    borderWidth: 1,
    borderRadius: Radii.sm,
    paddingVertical: 6,
    paddingHorizontal: 10,
  },
});
