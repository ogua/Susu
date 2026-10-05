import { useQuery } from '@tanstack/react-query';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useLayoutEffect } from 'react';
import { StyleSheet, View } from 'react-native';

import { getGroup } from '@/api/groups';
import { ThemedText } from '@/components/themed-text';
import {
  Avatar,
  Badge,
  Card,
  ErrorState,
  KeyValueRow,
  ListRow,
  LoadingState,
  ProgressBar,
  Screen,
  SectionHeader,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { formatDate } from '@/utils/format';
import { displayFormatted } from '@/utils/money';

export default function CustomerGroupDetailScreen() {
  const navigation = useNavigation();
  const { groupId } = useLocalSearchParams<{ groupId: string }>();

  const group = useQuery({
    queryKey: ['customer', 'group', groupId],
    queryFn: () => getGroup(groupId),
    enabled: !!groupId,
  });

  useLayoutEffect(() => {
    if (group.data?.name) {
      navigation.setOptions({ title: group.data.name });
    }
  }, [navigation, group.data?.name]);

  if (group.isLoading) {
    return <LoadingState label="Loading group…" />;
  }
  if (group.isError || !group.data) {
    return <ErrorState title="Couldn't load this group" onRetry={() => void group.refetch()} />;
  }

  const data = group.data;
  const members = data.members ?? [];

  return (
    <Screen>
      <Card>
        <View style={styles.rowBetween}>
          <ThemedText type="heading">{data.name}</ThemedText>
          <Badge label={data.status} />
        </View>
        <KeyValueRow label="Contribution" value={`${displayFormatted(data.contribution_amount_formatted)} per ${data.frequency === 'weekly' ? 'week' : 'month'}`} />
        <KeyValueRow label="Members" value={String(members.length)} />
      </Card>

      <SectionHeader title="Rounds" />
      {(data.rounds ?? []).map((round) => {
        const progress = round.total_expected > 0 ? round.total_collected / round.total_expected : 0;

        return (
          <Card key={round.id} style={styles.round}>
            <View style={styles.rowBetween}>
              <ThemedText type="label">
                Round {round.round_number} · {round.payout_member?.customer_name ?? '—'}
              </ThemedText>
              <Badge label={round.status} />
            </View>
            <ThemedText type="caption" themeColor="textMuted">
              Due {formatDate(round.due_date)}
              {round.paid_out_at ? ` · paid out ${formatDate(round.paid_out_at)}` : ''}
            </ThemedText>
            <ThemedText type="small">
              {displayFormatted(round.total_collected_formatted)} of {displayFormatted(round.total_expected_formatted)} collected
            </ThemedText>
            <ProgressBar progress={progress} accessibilityLabel={`Round ${round.round_number}, ${Math.round(progress * 100)}% collected`} />
          </Card>
        );
      })}

      <SectionHeader title="Rotation order" />
      <Card padded={false}>
        {members.map((member, index) => (
          <ListRow
            key={member.id}
            left={<Avatar name={member.customer_name} size={36} />}
            title={member.customer_name}
            subtitle={`Position ${member.rotation_position}`}
            divider={index < members.length - 1}
          />
        ))}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: Spacing.two },
  round: { gap: 6 },
});
