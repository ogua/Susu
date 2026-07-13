import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';

import { getAgentAccounts } from '@/api/accounts';
import { ThemedText } from '@/components/themed-text';
import type { SavingsAccount } from '@/types/api';

export default function AgentAccountsScreen() {
  const [search, setSearch] = useState('');

  const accounts = useQuery({
    queryKey: ['agent', 'accounts', search],
    queryFn: () => getAgentAccounts(search || undefined),
  });

  function renderItem({ item }: { item: SavingsAccount }) {
    const customerName = item.customer ? `${item.customer.first_name} ${item.customer.last_name}` : '';

    return (
      <View style={styles.row}>
        <Pressable
          style={{ flex: 1, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}
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
        >
          <View style={{ flex: 1 }}>
            <ThemedText type="smallBold">{customerName || item.account_number}</ThemedText>
            <ThemedText type="small">{item.account_number}</ThemedText>
          </View>
          <View style={{ alignItems: 'flex-end' }}>
            <ThemedText>{item.balance_formatted}</ThemedText>
            <ThemedText type="small">{item.status}</ThemedText>
          </View>
        </Pressable>
        <Pressable
          style={styles.loanButton}
          onPress={() =>
            router.push({
              pathname: '/(agent)/loans/apply/[accountId]',
              params: { accountId: item.id, customerId: item.customer_id, customerName },
            })
          }
        >
          <ThemedText type="small" style={styles.loanButtonText}>Loan</ThemedText>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <TextInput
        style={styles.search}
        placeholder="Search by name, account, or phone"
        value={search}
        onChangeText={setSearch}
        autoCapitalize="none"
      />

      {accounts.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : accounts.isError ? (
        <ThemedText style={styles.empty}>
          Could not load accounts. Check your connection and pull to retry.
        </ThemedText>
      ) : (
        <FlatList
          data={accounts.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          ItemSeparatorComponent={() => <View style={styles.separator} />}
          onRefresh={() => accounts.refetch()}
          refreshing={accounts.isRefetching}
          ListEmptyComponent={<ThemedText style={styles.empty}>No accounts found.</ThemedText>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, gap: 12 },
  search: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 10,
    fontSize: 15,
  },
  loanButton: {
    borderWidth: 1,
    borderColor: '#208AEF',
    borderRadius: 8,
    paddingVertical: 6,
    paddingHorizontal: 10,
    marginLeft: 8,
  },
  loanButtonText: { color: '#208AEF', fontWeight: '600' },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
  },
  separator: { height: 1, backgroundColor: '#e5e5ea' },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
