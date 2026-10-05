import { useQuery } from '@tanstack/react-query';
import { FlatList, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getLoans } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import { Badge, EmptyState, ErrorState, ListRow, LoadingState } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { Loan } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted } from '@/utils/money';

/** Loan list shared by staff and customer routes. */
export function LoanList({
  queryKey,
  onOpen,
  header,
  emptyHint,
}: {
  queryKey: readonly unknown[];
  onOpen: (loan: Loan) => void;
  header?: React.ReactNode;
  emptyHint: string;
}) {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const loans = useQuery({ queryKey, queryFn: () => getLoans() });

  function renderItem({ item }: { item: Loan }) {
    const showOutstanding = item.status === 'disbursed' || item.status === 'closed' || item.status === 'written_off';
    const when = item.disbursed_at ?? item.approved_at ?? item.applied_at;

    return (
      <ListRow
        icon="loan"
        title={item.loan_product?.name ?? 'Loan'}
        subtitle={`${item.loan_number}${when ? ` · ${formatDate(when)}` : ''}`}
        onPress={() => onOpen(item)}
        accessibilityLabel={`${item.loan_product?.name ?? 'Loan'} ${item.loan_number}, ${item.status.replaceAll('_', ' ')}, ${showOutstanding ? `outstanding ${displayFormatted(item.outstanding_balance_formatted)}` : `amount ${displayFormatted(item.principal_amount_formatted)}`}`}
        right={
          <View style={styles.right}>
            <ThemedText type="caption" themeColor="textMuted">
              {showOutstanding ? 'Outstanding' : 'Requested'}
            </ThemedText>
            <ThemedText type="money">
              {displayFormatted(showOutstanding ? item.outstanding_balance_formatted : item.principal_amount_formatted)}
            </ThemedText>
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
      data={loans.data?.data ?? []}
      keyExtractor={(item) => item.id}
      renderItem={renderItem}
      onRefresh={() => void loans.refetch()}
      refreshing={loans.isRefetching}
      ListHeaderComponent={header ? <View style={styles.header}>{header}</View> : null}
      ListEmptyComponent={
        loans.isLoading ? (
          <LoadingState label="Loading loans…" />
        ) : loans.isError ? (
          <ErrorState title="Couldn't load loans" onRetry={() => void loans.refetch()} />
        ) : (
          <EmptyState icon="loan" title="No loans yet" hint={emptyHint} />
        )
      }
    />
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three },
  right: { alignItems: 'flex-end', gap: 3 },
});
