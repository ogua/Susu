import { useQuery } from '@tanstack/react-query';
import { FlatList } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getGroups } from '@/api/groups';
import { Badge, EmptyState, ErrorState, ListRow, LoadingState } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { Group } from '@/types/api';
import { displayFormatted } from '@/utils/money';

const PER: Record<Group['frequency'], string> = { weekly: 'week', monthly: 'month' };

/** Rotating susu group list shared by staff and customer routes. */
export function GroupList({
  queryKey,
  onOpen,
  emptyTitle,
  emptyHint,
}: {
  queryKey: readonly unknown[];
  onOpen: (group: Group) => void;
  emptyTitle: string;
  emptyHint: string;
}) {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const groups = useQuery({ queryKey, queryFn: () => getGroups() });

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={groups.data?.data ?? []}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => (
        <ListRow
          icon="group"
          title={item.name}
          subtitle={`${displayFormatted(item.contribution_amount_formatted)} per ${PER[item.frequency]} · ${item.code}`}
          right={<Badge label={item.status} />}
          onPress={() => onOpen(item)}
          accessibilityLabel={`${item.name}, ${displayFormatted(item.contribution_amount_formatted)} per ${PER[item.frequency]}, ${item.status}`}
        />
      )}
      onRefresh={() => void groups.refetch()}
      refreshing={groups.isRefetching}
      ListEmptyComponent={
        groups.isLoading ? (
          <LoadingState label="Loading groups…" />
        ) : groups.isError ? (
          <ErrorState title="Couldn't load groups" onRetry={() => void groups.refetch()} />
        ) : (
          <EmptyState icon="group" title={emptyTitle} hint={emptyHint} />
        )
      }
    />
  );
}
