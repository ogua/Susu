import { useQuery, useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useLayoutEffect, useRef, useState } from 'react';
import { Alert, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { getGroup, payoutGroupRound, recordGroupContribution } from '@/api/groups';
import { ThemedText } from '@/components/themed-text';
import {
  Avatar,
  Badge,
  Button,
  Card,
  confirmAction,
  EmptyState,
  ErrorState,
  KeyValueRow,
  ListRow,
  LoadingState,
  Notice,
  ProgressBar,
  Screen,
  SectionHeader,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import type { GroupMember } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted, formatMoney } from '@/utils/money';

export default function AgentGroupDetailScreen() {
  const theme = useTheme();
  const navigation = useNavigation();
  const { groupId } = useLocalSearchParams<{ groupId: string }>();
  const user = useAuthStore((state) => state.user);
  const queryClient = useQueryClient();
  const [busyMemberId, setBusyMemberId] = useState<string | null>(null);
  const [payingOut, setPayingOut] = useState(false);
  const [lastRecorded, setLastRecorded] = useState<string | null>(null);
  // One idempotency key per member attempt; dropped only after success so a
  // retry after a timeout can't post the same contribution twice.
  const contributionKeys = useRef(new Map<string, string>());

  const canPayout = user?.role === 'branch_manager' || user?.role === 'company_admin';

  const group = useQuery({
    queryKey: ['group', groupId],
    queryFn: () => getGroup(groupId),
    enabled: !!groupId,
  });

  useLayoutEffect(() => {
    if (group.data?.name) {
      navigation.setOptions({ title: group.data.name });
    }
  }, [navigation, group.data?.name]);

  const currentRound = group.data?.rounds?.find((round) => round.status !== 'completed');

  async function contribute(member: GroupMember) {
    if (busyMemberId) return;
    let key = contributionKeys.current.get(member.id);
    if (!key) {
      key = Crypto.randomUUID();
      contributionKeys.current.set(member.id, key);
    }
    setBusyMemberId(member.id);
    try {
      const result = await recordGroupContribution(groupId, member.id, key);
      contributionKeys.current.delete(member.id);
      setLastRecorded(`${formatMoney(result.amount)} recorded for ${member.customer_name}.`);
      await queryClient.invalidateQueries({ queryKey: ['group', groupId] });
    } catch (err) {
      Alert.alert("Contribution wasn't recorded", apiErrorMessage(err));
    } finally {
      setBusyMemberId(null);
    }
  }

  function handleContribute(member: GroupMember) {
    const amount = displayFormatted(group.data?.contribution_amount_formatted);
    confirmAction({
      title: `Record ${amount}?`,
      message: `Contribution from ${member.customer_name} for round ${currentRound?.round_number ?? ''}.`,
      confirmLabel: `Record ${amount}`,
      onConfirm: () => void contribute(member),
    });
  }

  async function payout(override: boolean) {
    if (!currentRound || payingOut) return;
    setPayingOut(true);
    try {
      await payoutGroupRound(currentRound.id, override);
      await queryClient.invalidateQueries({ queryKey: ['group', groupId] });
      Alert.alert('Round paid out', `${displayFormatted(currentRound.total_collected_formatted)} paid to ${currentRound.payout_member?.customer_name ?? 'the member'}.`);
    } catch (err) {
      Alert.alert("Payout wasn't completed", apiErrorMessage(err));
    } finally {
      setPayingOut(false);
    }
  }

  function handlePayout(override: boolean) {
    if (!currentRound) return;
    const recipient = currentRound.payout_member?.customer_name ?? 'this round’s member';
    const collected = displayFormatted(currentRound.total_collected_formatted);
    confirmAction({
      title: override ? 'Pay out early?' : 'Pay out this round?',
      message: override
        ? `Only ${collected} of ${displayFormatted(currentRound.total_expected_formatted)} has been collected. ${recipient} will receive ${collected} now. This cannot be undone.`
        : `${recipient} will receive ${collected}. This cannot be undone.`,
      confirmLabel: `Pay ${collected}`,
      destructive: override,
      onConfirm: () => void payout(override),
    });
  }

  if (group.isLoading) {
    return <LoadingState label="Loading group…" />;
  }
  if (group.isError || !group.data) {
    return <ErrorState title="Couldn't load this group" onRetry={() => void group.refetch()} />;
  }

  const data = group.data;
  const fullyCollected = currentRound ? currentRound.total_collected >= currentRound.total_expected : false;
  const progress = currentRound && currentRound.total_expected > 0 ? currentRound.total_collected / currentRound.total_expected : 0;

  return (
    <Screen>
      <Card>
        <View style={styles.rowBetween}>
          <ThemedText type="caption" themeColor="textMuted">
            {data.code}
          </ThemedText>
          <Badge label={data.status} />
        </View>
        <KeyValueRow label="Contribution" value={`${displayFormatted(data.contribution_amount_formatted)} per ${data.frequency === 'weekly' ? 'week' : 'month'}`} />
        <KeyValueRow label="Members" value={String(data.members?.length ?? 0)} />
      </Card>

      {currentRound ? (
        <Card style={styles.section}>
          <View style={styles.rowBetween}>
            <ThemedText type="heading">Round {currentRound.round_number}</ThemedText>
            <Badge label={currentRound.status} />
          </View>
          <KeyValueRow label="Receives payout" value={currentRound.payout_member?.customer_name ?? '—'} />
          <KeyValueRow label="Due" value={formatDate(currentRound.due_date)} />
          <View style={styles.rowBetween}>
            <ThemedText type="money">{displayFormatted(currentRound.total_collected_formatted)}</ThemedText>
            <ThemedText type="small" themeColor="textMuted">
              of {displayFormatted(currentRound.total_expected_formatted)} collected
            </ThemedText>
          </View>
          <ProgressBar
            progress={progress}
            color={fullyCollected ? theme.success : undefined}
            accessibilityLabel={`${Math.round(progress * 100)}% of round collected`}
          />
          {canPayout ? (
            <View style={styles.payoutRow}>
              <Button
                title="Pay out"
                icon="cash"
                variant="success"
                style={styles.flex}
                disabled={!fullyCollected}
                loading={payingOut}
                onPress={() => handlePayout(false)}
              />
              {currentRound.total_collected > 0 && !fullyCollected ? (
                <Button title="Pay out early" variant="outline" style={styles.flex} disabled={payingOut} onPress={() => handlePayout(true)} />
              ) : null}
            </View>
          ) : null}
        </Card>
      ) : (
        <Notice
          tone="info"
          message={data.status === 'draft' ? 'This group has not been activated yet.' : 'All rounds have been paid out.'}
        />
      )}

      {lastRecorded ? <Notice tone="success" message={lastRecorded} /> : null}

      <SectionHeader title="Members · rotation order" />
      <Card padded={false}>
        {(data.members ?? []).length === 0 ? (
          <EmptyState icon="group" title="No members yet" />
        ) : (
          (data.members ?? []).map((member, index, all) => (
            <ListRow
              key={member.id}
              left={<Avatar name={member.customer_name} size={36} />}
              title={member.customer_name}
              subtitle={`Position ${member.rotation_position}${member.status === 'left' ? ' · left group' : ''}`}
              divider={index < all.length - 1}
              right={
                currentRound && member.status === 'active' ? (
                  <Button
                    title="Collect"
                    variant="secondary"
                    loading={busyMemberId === member.id}
                    disabled={!!busyMemberId && busyMemberId !== member.id}
                    onPress={() => handleContribute(member)}
                    accessibilityLabel={`Record contribution from ${member.customer_name}`}
                  />
                ) : undefined
              }
            />
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  section: { gap: Spacing.two },
  payoutRow: { flexDirection: 'row', gap: Spacing.two, marginTop: Spacing.two },
});
