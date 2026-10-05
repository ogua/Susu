import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { FlatList, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getGroupLoans } from '@/api/groupLoans';
import { ThemedText } from '@/components/themed-text';
import { Avatar, Badge, Button, EmptyState, ErrorState, ListRow, SkeletonList } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { GroupLoan } from '@/types/api';
import { displayFormatted } from '@/utils/money';

export default function AgentGroupLoansScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const groupLoans = useQuery({
    queryKey: ['agent', 'groupLoans'],
    queryFn: () => getGroupLoans(),
  });

  function renderItem({ item }: { item: GroupLoan }) {
    const name = item.customer_name ?? item.loan_group?.name ?? item.loan_number;

    return (
      <ListRow
        left={<Avatar name={name} />}
        title={name}
        subtitle={`${item.loan_number}${item.loan_group?.name && item.customer_name ? ` · ${item.loan_group.name}` : ''}`}
        onPress={() => router.push({ pathname: '/(agent)/group-loans/[groupLoanId]', params: { groupLoanId: item.id } })}
        accessibilityLabel={`${name}, ${item.loan_number}, outstanding ${displayFormatted(item.outstanding_balance_formatted)}, ${item.status}`}
        right={
          <View style={styles.right}>
            <ThemedText type="caption" themeColor="textMuted">
              Outstanding
            </ThemedText>
            <ThemedText type="money">{displayFormatted(item.outstanding_balance_formatted)}</ThemedText>
            <Badge label={item.status} />
          </View>
        }
      />
    );
  }

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={groupLoans.data?.data ?? []}
      keyExtractor={(item) => item.id}
      renderItem={renderItem}
      onRefresh={() => void groupLoans.refetch()}
      refreshing={groupLoans.isRefetching}
      ListHeaderComponent={
        <View style={styles.header}>
          <Button title="Issue a member loan" icon="add" onPress={() => router.push('/(agent)/group-loans/apply')} />
        </View>
      }
      ListEmptyComponent={
        groupLoans.isLoading ? (
          <SkeletonList />
        ) : groupLoans.isError ? (
          <ErrorState title="Couldn't load group loans" onRetry={() => void groupLoans.refetch()} />
        ) : (
          <EmptyState icon="savings" title="No group loans yet" hint="Issue a loan to a member of one of your loan groups to get started." />
        )
      }
    />
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three },
  right: { alignItems: 'flex-end', gap: 3 },
});
