import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getLoans } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import type { Loan } from '@/types/api';
import { Palette } from '@/constants/theme';

const STATUS_COLORS: Record<Loan['status'], string> = {
  applied: '#a16207',
  approved: Palette.primary500,
  rejected: Palette.danger,
  disbursed: Palette.success,
  closed: Palette.neutral,
  written_off: Palette.neutral,
};

export default function AgentLoansScreen() {
  const loans = useQuery({
    queryKey: ['agent', 'loans'],
    queryFn: () => getLoans(),
  });

  function renderItem({ item }: { item: Loan }) {
    return (
      <Pressable
        style={styles.row}
        onPress={() => router.push({ pathname: '/(agent)/loans/[loanId]', params: { loanId: item.id } })}
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
  container: { flex: 1, padding: 16 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 12 },
  separator: { height: 1, backgroundColor: Palette.border },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
