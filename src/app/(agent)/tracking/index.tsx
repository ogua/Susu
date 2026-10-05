import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { getAgentPositions } from '@/api/tracking';
import { ThemedText } from '@/components/themed-text';
import { TrackingMap } from '@/components/tracking-map';
import { Avatar, EmptyState, ErrorState, ListRow, OfflineBanner, SkeletonList } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { AgentPosition, AgentTrackingStatus } from '@/types/api';
import { TRACKING_STATUS_COLORS, TRACKING_STATUS_LABELS } from '@/utils/tracking';

const REFRESH_MS = 15_000;

const ORDER: Record<AgentTrackingStatus, number> = { active: 0, stale: 1, off_duty: 2 };

function openAgent(agent: AgentPosition) {
  router.push({ pathname: '/(agent)/tracking/[agentId]', params: { agentId: agent.id, name: agent.name } });
}

/** Managers: where the branch's agents are right now (same data as the web map). */
export default function AgentTrackingScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const [pulling, setPulling] = useState(false);
  const positions = useQuery({
    queryKey: ['agents', 'positions'],
    queryFn: getAgentPositions,
    refetchInterval: REFRESH_MS,
  });

  const agents = useMemo(
    () => [...(positions.data?.data ?? [])].sort((a, b) => ORDER[a.status] - ORDER[b.status] || a.name.localeCompare(b.name)),
    [positions.data],
  );
  const counts = useMemo(
    () => ({
      active: agents.filter((a) => a.status === 'active').length,
      stale: agents.filter((a) => a.status === 'stale').length,
      off_duty: agents.filter((a) => a.status === 'off_duty').length,
    }),
    [agents],
  );
  const mapPayload = useMemo(() => ({ mode: 'overview' as const, agents }), [agents]);

  return (
    <FlatList
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={{ paddingBottom: insets.bottom + Spacing.four }}
      data={agents}
      keyExtractor={(item) => item.id}
      ListHeaderComponent={
        <View style={styles.header}>
          <OfflineBanner mode="agent" />
          {agents.length ? (
            <TrackingMap
              payload={mapPayload}
              height={300}
              onAgentPress={(id) => {
                const agent = agents.find((a) => a.id === id);
                if (agent) openAgent(agent);
              }}
            />
          ) : null}
          {positions.data ? (
            <View style={styles.counts}>
              {(Object.keys(TRACKING_STATUS_LABELS) as AgentTrackingStatus[]).map((status) => (
                <View key={status} style={styles.count}>
                  <View style={[styles.dot, { backgroundColor: TRACKING_STATUS_COLORS[status] }]} />
                  <ThemedText type="small" themeColor="textSecondary">
                    {counts[status]} {TRACKING_STATUS_LABELS[status].toLowerCase()}
                  </ThemedText>
                </View>
              ))}
            </View>
          ) : null}
          {positions.data ? (
            <ThemedText type="caption" themeColor="textMuted">
              {positions.data.branch.name} · updates every 15 seconds · tap an agent to see their route
            </ThemedText>
          ) : null}
        </View>
      }
      renderItem={({ item }) => (
        <ListRow
          left={
            <View>
              <Avatar name={item.name} uri={item.photo_url} size={44} />
              <View style={[styles.avatarDot, { backgroundColor: TRACKING_STATUS_COLORS[item.status], borderColor: theme.background }]} />
            </View>
          }
          title={item.name}
          subtitle={`${TRACKING_STATUS_LABELS[item.status]} · ${item.located_at_human ?? 'never seen'}`}
          value={item.collections_total_formatted}
          subvalue={`${item.collections_count} collection${item.collections_count === 1 ? '' : 's'}`}
          onPress={() => openAgent(item)}
          accessibilityLabel={`${item.name}, ${TRACKING_STATUS_LABELS[item.status]}, last seen ${item.located_at_human ?? 'never'}, ${item.collections_total_formatted} collected today`}
        />
      )}
      onRefresh={() => {
        setPulling(true);
        void positions.refetch().finally(() => setPulling(false));
      }}
      refreshing={pulling}
      ListEmptyComponent={
        positions.isLoading ? (
          <SkeletonList />
        ) : positions.isError ? (
          <ErrorState title="Couldn't load agents" onRetry={() => void positions.refetch()} />
        ) : (
          <EmptyState
            icon="location"
            title="No agents on the map yet"
            hint="Agents appear here once they go on duty in the app and share their location."
          />
        )
      }
    />
  );
}

const styles = StyleSheet.create({
  header: { padding: Spacing.three, gap: Spacing.two },
  counts: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.three },
  count: { flexDirection: 'row', alignItems: 'center', gap: Spacing.one },
  dot: { width: 8, height: 8, borderRadius: 4 },
  avatarDot: { position: 'absolute', right: -1, bottom: -1, width: 12, height: 12, borderRadius: 6, borderWidth: 2 },
});
