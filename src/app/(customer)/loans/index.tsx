import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getLoans } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import type { Loan } from '@/types/api';

const STATUS_COLORS: Record<Loan['status'], string> = {
  applied: '#a16207',
  approved: '#208AEF',
  rejected: '#d11a2a',
  disbursed: '#1a8a3d',
  closed: '#6b7280',
  written_off: '#6b7280',
};

export default function CustomerLoansScreen() {
  const loans = useQuery({
    queryKey: ['customer', 'loans'],
    queryFn: () => getLoans(),
  });

  function renderItem({ item }: { item: Loan }) {
    return (
      <Pressable
        style={styles.row}
        onPress={() => router.push({ pathname: '/(customer)/loans/[loanId]', params: { loanId: item.id } })}
      >
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">{item.loan_number}</ThemedText>
          <ThemedText type="small">{item.loan_product?.name}</ThemedText>
        </View>
        <View style={{ alignItems: 'flex-end' }}>
          <ThemedText>{item.outstanding_balance_formatted}</ThemedText>
          <ThemedText type="small" style={{ color: STATUS_COLORS[item.status] }}>
            {item.status.replaceAll('_', ' ')}
          </ThemedText>
        </View>
      </Pressable>
    );
  }

  return (
    <View style={styles.container}>
      <Pressable style={styles.button} onPress={() => router.push('/(customer)/loans/apply')}>
        <ThemedText style={styles.buttonText}>Apply for a Loan</ThemedText>
      </Pressable>

      {loans.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : loans.isError ? (
        <ThemedText style={styles.empty}>Could not load loans. Pull down to retry.</ThemedText>
      ) : (
        <FlatList
          data={loans.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          ItemSeparatorComponent={() => <View style={styles.separator} />}
          onRefresh={() => loans.refetch()}
          refreshing={loans.isRefetching}
          ListEmptyComponent={<ThemedText style={styles.empty}>No loans yet.</ThemedText>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, gap: 12 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 12 },
  separator: { height: 1, backgroundColor: '#e5e5ea' },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonText: { color: '#ffffff', fontWeight: '600' },
});
