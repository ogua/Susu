import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getAccountTransactions } from '@/api/accounts';
import { ThemedText } from '@/components/themed-text';
import type { Transaction } from '@/types/api';

const TYPE_LABELS: Record<Transaction['type'], string> = {
  collection: 'Deposit',
  commission: 'Commission',
  withdrawal: 'Withdrawal',
  remittance: 'Remittance',
  reversal: 'Correction',
  adjustment: 'Adjustment',
};

export default function AccountDetailScreen() {
  const { accountId } = useLocalSearchParams<{ accountId: string }>();

  const transactions = useQuery({
    queryKey: ['customer', 'account', accountId, 'transactions'],
    queryFn: () => getAccountTransactions(accountId),
    enabled: !!accountId,
  });

  function renderItem({ item }: { item: Transaction }) {
    const isCredit = item.type === 'withdrawal';

    return (
      <View style={styles.row}>
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">{TYPE_LABELS[item.type] ?? item.type}</ThemedText>
          <ThemedText type="small">{new Date(item.recorded_at).toLocaleString()}</ThemedText>
        </View>
        <ThemedText style={{ color: isCredit ? '#d11a2a' : '#1a8a3d', fontWeight: '700' }}>
          {isCredit ? '-' : '+'}
          {item.amount_formatted}
        </ThemedText>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.actionRow}>
        <Pressable style={styles.depositButton} onPress={() => router.push({ pathname: '/(customer)/deposit', params: { accountId } })}>
          <ThemedText style={styles.depositButtonText}>Deposit via Mobile Money</ThemedText>
        </Pressable>
        <Pressable style={styles.withdrawButton} onPress={() => router.push({ pathname: '/(customer)/withdraw', params: { accountId } })}>
          <ThemedText style={styles.withdrawButtonText}>Request Withdrawal</ThemedText>
        </Pressable>
      </View>

      {transactions.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : transactions.isError ? (
        <ThemedText style={styles.empty}>Could not load transactions.</ThemedText>
      ) : (
        <FlatList
          data={transactions.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          ItemSeparatorComponent={() => <View style={styles.separator} />}
          onRefresh={() => transactions.refetch()}
          refreshing={transactions.isRefetching}
          ListEmptyComponent={<ThemedText style={styles.empty}>No transactions yet.</ThemedText>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, gap: 12 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 12 },
  separator: { height: 1, backgroundColor: '#e5e5ea' },
  actionRow: { gap: 8 },
  depositButton: {
    backgroundColor: '#1a8a3d',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  depositButtonText: { color: '#ffffff', fontWeight: '600' },
  withdrawButton: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  withdrawButtonText: { color: '#ffffff', fontWeight: '600' },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
