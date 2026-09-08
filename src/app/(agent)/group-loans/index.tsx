import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getGroupLoans } from '@/api/groupLoans';
import { ThemedText } from '@/components/themed-text';
import type { GroupLoan } from '@/types/api';
import { Palette } from '@/constants/theme';

const STATUS_COLORS: Record<GroupLoan['status'], string> = {
  draft: '#a16207',
  active: Palette.success,
  closed: Palette.neutral,
  written_off: Palette.neutral,
};

export default function AgentGroupLoansScreen() {
  const groupLoans = useQuery({
    queryKey: ['agent', 'groupLoans'],
    queryFn: () => getGroupLoans(),
  });

  function renderItem({ item }: { item: GroupLoan }) {
    return (
      <Pressable
        style={styles.row}
        onPress={() => router.push({ pathname: '/(agent)/group-loans/[groupLoanId]', params: { groupLoanId: item.id } })}
      >
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">{item.loan_number}</ThemedText>
          <ThemedText type="small">
            {item.customer_name ?? item.loan_group?.name}
            {item.customer_name && item.loan_group?.name ? ` · ${item.loan_group.name}` : ''}
          </ThemedText>
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
      <Pressable style={styles.applyButton} onPress={() => router.push('/(agent)/group-loans/apply')}>
        <ThemedText style={styles.applyButtonText}>+ Issue Member Loan</ThemedText>
      </Pressable>

      {groupLoans.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : groupLoans.isError ? (
        <ThemedText style={styles.empty}>Could not load group loans. Pull down to retry.</ThemedText>
      ) : (
        <FlatList
          data={groupLoans.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          ItemSeparatorComponent={() => <View style={styles.separator} />}
          onRefresh={() => groupLoans.refetch()}
          refreshing={groupLoans.isRefetching}
          ListEmptyComponent={<ThemedText style={styles.empty}>No group loans yet.</ThemedText>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16 },
  applyButton: {
    backgroundColor: Palette.primary500,
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
    marginBottom: 12,
  },
  applyButtonText: { color: '#ffffff', fontWeight: '700' },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 12 },
  separator: { height: 1, backgroundColor: Palette.border },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
