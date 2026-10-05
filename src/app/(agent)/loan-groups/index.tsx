import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { FlatList } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getLoanGroups } from '@/api/loanGroups';
import { Avatar, EmptyState, ErrorState, ListRow, SkeletonList } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { LoanGroup } from '@/types/api';
import { displayFormatted } from '@/utils/money';

/** Customer groups (the backend's loan groups) — opens each group's overview. */
export default function LoanGroupsScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const groups = useQuery({ queryKey: ['agent', 'loanGroups'], queryFn: () => getLoanGroups() });

  function renderItem({ item }: { item: LoanGroup }) {
    return (
      <ListRow
        left={<Avatar name={item.name} />}
        title={item.name}
        subtitle={`${item.code} · ${item.member_count ?? 0} member(s)${item.is_active ? '' : ' · inactive'}`}
        value={displayFormatted(item.group_outstanding_formatted)}
        valueLabel="Outstanding"
        onPress={() => router.push({ pathname: '/(agent)/loan-groups/[loanGroupId]', params: { loanGroupId: item.id } })}
      />
    );
  }

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={groups.data?.data ?? []}
      keyExtractor={(item) => item.id}
      renderItem={renderItem}
      onRefresh={() => void groups.refetch()}
      refreshing={groups.isRefetching}
      ListEmptyComponent={
        groups.isLoading ? (
          <SkeletonList />
        ) : groups.isError ? (
          <ErrorState title="Couldn't load customer groups" onRetry={() => void groups.refetch()} />
        ) : (
          <EmptyState icon="group" title="No customer groups yet" hint="Groups are created from the web or desktop app." />
        )
      }
    />
  );
}
