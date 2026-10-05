import { useQuery } from '@tanstack/react-query';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';

import { getAgentPositions, getAgentRoute } from '@/api/tracking';
import { ThemedText } from '@/components/themed-text';
import { TrackingMap, type MapAgent } from '@/components/tracking-map';
import {
  Avatar,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Icon,
  IconButton,
  LoadingState,
  OfflineBanner,
  ProgressBar,
  Screen,
  SectionHeader,
  StatTile,
  StatTileRow,
} from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { AgentRoute } from '@/types/api';
import { initials } from '@/utils/format';
import {
  formatDuration,
  formatIsoDate,
  isoDate,
  shiftIsoDate,
  TRACKING_STATUS_COLORS,
  TRACKING_STATUS_LABELS,
} from '@/utils/tracking';

const LIVE_REFRESH_MS = 15_000;
const REPLAY_STEP_MS = 400;

type TimelineEvent = {
  key: string;
  kind: 'start' | 'stop' | 'collection' | 'end';
  badge: string;
  at: string;
  time: string;
  title: string;
  subtitle: string | null;
  lat: number;
  lng: number;
};

const KIND_COLORS: Record<TimelineEvent['kind'], string> = {
  start: '#16a34a',
  stop: '#f59e0b',
  collection: '#0d9488',
  end: '#dc2626',
};

function buildTimeline(route: AgentRoute, isToday: boolean): TimelineEvent[] {
  const events: TimelineEvent[] = [];
  const first = route.points[0];
  const last = route.points[route.points.length - 1];

  if (first) {
    events.push({ key: 'start', kind: 'start', badge: 'S', at: first.at, time: first.time, title: 'Started route', subtitle: null, lat: first.lat, lng: first.lng });
  }
  route.stops.forEach((stop, i) =>
    events.push({
      key: `stop-${i}`,
      kind: 'stop',
      badge: String(i + 1),
      at: stop.arrived_at,
      time: `${stop.arrived_time} – ${stop.left_time}`,
      title: `Stopped ${stop.minutes} min`,
      subtitle: `Stop ${i + 1}`,
      lat: stop.lat,
      lng: stop.lng,
    }),
  );
  route.collections.forEach((collection, i) =>
    events.push({
      key: `collection-${i}`,
      kind: 'collection',
      badge: '₵',
      at: collection.at,
      time: collection.time,
      title: `Collected ${collection.amount_formatted}`,
      subtitle: collection.description,
      lat: collection.lat,
      lng: collection.lng,
    }),
  );
  if (last && route.points.length > 1) {
    events.push({
      key: 'end',
      kind: 'end',
      badge: isToday ? '●' : 'E',
      at: last.at,
      time: last.time,
      title: isToday ? 'Latest position' : 'Last position',
      subtitle: null,
      lat: last.lat,
      lng: last.lng,
    });
  }

  return events.sort((a, b) => Date.parse(a.at) - Date.parse(b.at));
}

/** Managers: one agent's route for a day — live today, replayable for past days. */
export default function TrackAgentScreen() {
  const theme = useTheme();
  const { agentId, name } = useLocalSearchParams<{ agentId: string; name?: string }>();
  const today = isoDate(new Date());
  const [date, setDate] = useState(today);
  const [index, setIndex] = useState<number | null>(null);
  const [playing, setPlaying] = useState(false);
  const [focus, setFocus] = useState<{ lat: number; lng: number; key: string } | null>(null);
  const focusCount = useRef(0);
  const isToday = date === today;

  // Shares the overview's cache: gives the same colour, photo and status as the list.
  const positions = useQuery({ queryKey: ['agents', 'positions'], queryFn: getAgentPositions, refetchInterval: LIVE_REFRESH_MS });
  const route = useQuery({
    queryKey: ['agents', agentId, 'route', date],
    queryFn: () => getAgentRoute(agentId, date),
    refetchInterval: isToday ? LIVE_REFRESH_MS : false,
  });

  const position = positions.data?.data.find((p) => p.id === agentId);
  const agentName = route.data?.agent.name ?? position?.name ?? name ?? 'Agent';
  const phone = route.data?.agent.phone ?? position?.phone ?? null;
  const points = route.data?.points ?? [];
  const lastIndex = Math.max(points.length - 1, 0);
  const current = Math.min(index ?? lastIndex, lastIndex);

  const mapAgent: MapAgent = useMemo(
    () => ({
      id: agentId,
      first_name: position?.first_name ?? agentName.split(' ')[0],
      initials: position?.initials ?? initials(agentName),
      color: position?.color ?? theme.primary,
      photo_url: route.data?.agent.photo_url ?? position?.photo_url ?? null,
      status: position?.status ?? (route.data?.agent.on_duty ? 'active' : 'off_duty'),
    }),
    [agentId, agentName, position, route.data, theme.primary],
  );

  const mapPayload = useMemo(
    () => (route.data ? { mode: 'route' as const, agent: mapAgent, route: route.data, index: current } : null),
    [route.data, mapAgent, current],
  );
  const timeline = useMemo(() => (route.data ? buildTimeline(route.data, isToday) : []), [route.data, isToday]);

  // Replay steps one point per tick until it reaches the latest point.
  const replayDone = playing && current >= lastIndex;
  useEffect(() => {
    if (!playing || replayDone) {
      return;
    }
    const timer = setTimeout(() => setIndex(current + 1 >= lastIndex ? null : current + 1), REPLAY_STEP_MS);

    return () => clearTimeout(timer);
  }, [playing, replayDone, current, lastIndex]);

  if (replayDone && index === null && playing) {
    setPlaying(false);
  }

  /** A new day opens at its latest point. */
  function changeDate(next: string) {
    setDate(next);
    setIndex(null);
    setPlaying(false);
  }

  function togglePlay() {
    if (playing) {
      setPlaying(false);

      return;
    }
    if (current >= lastIndex) {
      setIndex(0);
    }
    setPlaying(true);
  }

  function step(delta: number) {
    setPlaying(false);
    const next = Math.max(0, Math.min(current + delta, lastIndex));
    setIndex(next >= lastIndex ? null : next);
  }

  function focusEvent(event: TimelineEvent) {
    setPlaying(false);
    const eventTime = Date.parse(event.at);
    let nearest = 0;
    points.forEach((point, i) => {
      if (Date.parse(point.at) <= eventTime) nearest = i;
    });
    setIndex(nearest >= lastIndex ? null : nearest);
    setFocus({ lat: event.lat, lng: event.lng, key: `${event.key}-${++focusCount.current}` });
  }

  const status = mapAgent.status;
  const summary = route.data?.summary;

  return (
    <Screen>
      <Stack.Screen options={{ title: agentName }} />
      <OfflineBanner mode="agent" />

      <Card style={styles.header}>
        <View>
          <Avatar name={agentName} uri={mapAgent.photo_url} size={56} />
          <View style={[styles.avatarDot, { backgroundColor: TRACKING_STATUS_COLORS[status], borderColor: theme.surface }]} />
        </View>
        <View style={styles.flex}>
          <ThemedText type="bodyStrong" numberOfLines={1}>
            {agentName}
          </ThemedText>
          <ThemedText type="caption" themeColor="textSecondary">
            {TRACKING_STATUS_LABELS[status]} · last seen {position?.located_at_human ?? 'never'}
          </ThemedText>
          {isToday ? (
            <ThemedText type="caption" style={{ color: '#dc2626', fontWeight: '700' }}>
              ● LIVE · updates every 15 seconds
            </ThemedText>
          ) : null}
        </View>
        {phone ? <IconButton icon="phone" label={`Call ${agentName}`} onPress={() => void Linking.openURL(`tel:${phone}`)} color={theme.primary} /> : null}
      </Card>

      <View style={[styles.dateBar, { borderColor: theme.border, backgroundColor: theme.surface }]}>
        <IconButton icon="chevronLeft" label="Previous day" onPress={() => changeDate(shiftIsoDate(date, -1))} />
        <Pressable style={styles.flex} onPress={() => changeDate(today)} accessibilityRole="button" accessibilityLabel="Jump to today">
          <ThemedText type="label" style={styles.center}>
            {isToday ? 'Today' : formatIsoDate(date)}
          </ThemedText>
          {!isToday ? (
            <ThemedText type="caption" themeColor="textMuted" style={styles.center}>
              Tap for today
            </ThemedText>
          ) : null}
        </Pressable>
        <IconButton
          icon="chevronRight"
          label="Next day"
          onPress={() => !isToday && changeDate(shiftIsoDate(date, 1))}
          color={isToday ? theme.textMuted : undefined}
        />
      </View>

      {route.isLoading ? (
        <LoadingState />
      ) : route.isError ? (
        <ErrorState title="Couldn't load this route" onRetry={() => void route.refetch()} />
      ) : route.data && summary ? (
        <>
          <StatTileRow>
            <StatTile icon="location" label="Distance" value={`${summary.distance_km.toFixed(2)} km`} hint={`${summary.points_count} updates`} />
            <StatTile
              icon="clock"
              label="Time on route"
              value={formatDuration(summary.duration_minutes)}
              hint={summary.started_time ? `${summary.started_time} – ${summary.ended_time}` : undefined}
            />
          </StatTileRow>
          <StatTileRow>
            <StatTile icon="pending" label="Stops" value={String(summary.stops_count)} hint="10+ min in one place" />
            <StatTile icon="cash" label="Collections on map" value={String(summary.collections_count)} hint={summary.collections_total_formatted} />
          </StatTileRow>

          {points.length ? (
            <>
              {mapPayload ? <TrackingMap payload={mapPayload} focus={focus} height={340} /> : null}

              <Card style={styles.player}>
                <IconButton icon="chevronLeft" label="Step back" onPress={() => step(-1)} />
                <Button title={playing ? 'Pause' : 'Replay'} icon={playing ? 'pause' : 'play'} variant="outline" onPress={togglePlay} />
                <IconButton icon="chevronRight" label="Step forward" onPress={() => step(1)} />
                <View style={styles.flex}>
                  <ThemedText type="label" style={styles.right}>
                    {points[current]?.time ?? '—'}
                  </ThemedText>
                  <ProgressBar progress={lastIndex ? current / lastIndex : 1} color={mapAgent.color} accessibilityLabel="Replay position" />
                </View>
              </Card>

              <SectionHeader title="Timeline" />
              <Card padded={false}>
                {timeline.map((event, i) => (
                  <Pressable
                    key={event.key}
                    onPress={() => focusEvent(event)}
                    style={({ pressed }) => [styles.event, pressed && { backgroundColor: theme.surfaceMuted }]}
                    accessibilityRole="button"
                    accessibilityLabel={`${event.time}, ${event.title}${event.subtitle ? `, ${event.subtitle}` : ''}. Show on map`}
                  >
                    <View style={styles.rail}>
                      <View style={[styles.railLine, { backgroundColor: theme.border, top: i === 0 ? '50%' : 0, bottom: i === timeline.length - 1 ? '50%' : 0 }]} />
                      <View style={[styles.badge, { backgroundColor: KIND_COLORS[event.kind], borderColor: theme.surface }]}>
                        <ThemedText type="caption" style={styles.badgeText}>
                          {event.badge}
                        </ThemedText>
                      </View>
                    </View>
                    <View style={styles.flex}>
                      <ThemedText type="caption" themeColor="textMuted">
                        {event.time}
                      </ThemedText>
                      <ThemedText type="label">{event.title}</ThemedText>
                      {event.subtitle ? (
                        <ThemedText type="caption" themeColor="textSecondary" numberOfLines={1}>
                          {event.subtitle}
                        </ThemedText>
                      ) : null}
                    </View>
                    <Icon name="map" size={16} color={theme.textMuted} />
                  </Pressable>
                ))}
              </Card>
            </>
          ) : (
            <Card>
              <EmptyState
                icon="location"
                title="No movement recorded"
                hint="Agents share their location only while on duty in the app. Try another day."
              />
            </Card>
          )}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  center: { textAlign: 'center' },
  right: { textAlign: 'right', marginBottom: 4 },
  header: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  avatarDot: { position: 'absolute', right: 0, bottom: 0, width: 14, height: 14, borderRadius: 7, borderWidth: 2 },
  dateBar: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: Radii.lg,
    paddingHorizontal: Spacing.one,
    paddingVertical: Spacing.one,
  },
  player: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  event: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three, paddingHorizontal: Spacing.three, paddingVertical: Spacing.two },
  rail: { width: 28, alignSelf: 'stretch', alignItems: 'center', justifyContent: 'center' },
  railLine: { position: 'absolute', width: 2 },
  badge: { width: 28, height: 28, borderRadius: 14, alignItems: 'center', justifyContent: 'center', borderWidth: 2 },
  badgeText: { color: '#fff', fontWeight: '700' },
});
