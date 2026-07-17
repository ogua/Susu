import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { getGroup, payoutGroupRound, recordGroupContribution } from '@/api/groups';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';
import type { GroupMember } from '@/types/api';
import { Palette } from '@/constants/theme';

export default function AgentGroupDetailScreen() {
  const { groupId } = useLocalSearchParams<{ groupId: string }>();
  const user = useAuthStore((state) => state.user);
  const queryClient = useQueryClient();
  const [busyMemberId, setBusyMemberId] = useState<string | null>(null);
  const [payingOut, setPayingOut] = useState(false);

  const canPayout = user?.role === 'branch_manager' || user?.role === 'company_admin';

  const group = useQuery({
    queryKey: ['group', groupId],
    queryFn: () => getGroup(groupId),
    enabled: !!groupId,
  });

  const currentRound = group.data?.rounds?.find((round) => round.status !== 'completed');

  async function handleContribute(member: GroupMember) {
    setBusyMemberId(member.id);
    try {
      await recordGroupContribution(groupId, member.id);
      await queryClient.invalidateQueries({ queryKey: ['group', groupId] });
    } catch (err) {
      Alert.alert('Could not record contribution', apiErrorMessage(err));
    } finally {
      setBusyMemberId(null);
    }
  }

  async function handlePayout(override: boolean) {
    if (!currentRound) {
      return;
    }
    setPayingOut(true);
    try {
      await payoutGroupRound(currentRound.id, override);
      await queryClient.invalidateQueries({ queryKey: ['group', groupId] });
      Alert.alert('Round paid out');
    } catch (err) {
      Alert.alert('Could not pay out round', apiErrorMessage(err));
    } finally {
      setPayingOut(false);
    }
  }

  if (group.isLoading) {
    return <ActivityIndicator style={{ marginTop: 24 }} />;
  }
  if (group.isError || !group.data) {
    return <ThemedText style={styles.empty}>Could not load this group.</ThemedText>;
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <View style={styles.card}>
        <ThemedText type="subtitle">{group.data.name}</ThemedText>
        <ThemedText type="small">{group.data.code} · {group.data.contribution_amount_formatted} per {group.data.frequency.replace('ly', '')}</ThemedText>
        <ThemedText type="small" style={styles.status}>{group.data.status}</ThemedText>
      </View>

      {currentRound ? (
        <View style={styles.card}>
          <ThemedText type="subtitle">Round {currentRound.round_number}</ThemedText>
          <ThemedText type="small">Payout to: {currentRound.payout_member?.customer_name}</ThemedText>
          <ThemedText>
            {currentRound.total_collected_formatted} / {currentRound.total_expected_formatted} collected
          </ThemedText>
          {canPayout ? (
            <View style={styles.payoutRow}>
              <Pressable
                style={[styles.payoutButton, currentRound.total_collected < currentRound.total_expected && styles.payoutButtonDisabled]}
                disabled={payingOut || currentRound.total_collected < currentRound.total_expected}
                onPress={() => handlePayout(false)}
              >
                <ThemedText style={styles.payoutButtonText}>Payout</ThemedText>
              </Pressable>
              {currentRound.total_collected > 0 && currentRound.total_collected < currentRound.total_expected ? (
                <Pressable
                  style={styles.overrideButton}
                  disabled={payingOut}
                  onPress={() =>
                    Alert.alert(
                      'Pay out early?',
                      'Collection is incomplete. Pay out what has been collected so far?',
                      [
                        { text: 'Cancel', style: 'cancel' },
                        { text: 'Pay out', onPress: () => handlePayout(true) },
                      ],
                    )
                  }
                >
                  <ThemedText style={styles.overrideButtonText}>Override</ThemedText>
                </Pressable>
              ) : null}
            </View>
          ) : null}
        </View>
      ) : (
        <View style={styles.card}>
          <ThemedText type="small">
            {group.data.status === 'draft' ? 'This group has not been activated yet.' : 'All rounds have been paid out.'}
          </ThemedText>
        </View>
      )}

      <ThemedText type="subtitle" style={styles.sectionTitle}>Members</ThemedText>
      {(group.data.members ?? []).map((member) => (
        <View key={member.id} style={styles.memberRow}>
          <View style={{ flex: 1 }}>
            <ThemedText type="smallBold">#{member.rotation_position} {member.customer_name}</ThemedText>
          </View>
          {currentRound ? (
            <Pressable
              style={styles.contributeButton}
              disabled={busyMemberId === member.id}
              onPress={() => handleContribute(member)}
            >
              {busyMemberId === member.id ? (
                <ActivityIndicator size="small" color="#ffffff" />
              ) : (
                <ThemedText style={styles.contributeButtonText}>Contribute</ThemedText>
              )}
            </Pressable>
          ) : null}
        </View>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 12 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Palette.border,
    padding: 16,
    gap: 4,
    backgroundColor: '#ffffff',
  },
  status: { textTransform: 'capitalize', opacity: 0.7 },
  sectionTitle: { marginTop: 8 },
  memberRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: Palette.border,
  },
  contributeButton: {
    backgroundColor: Palette.primary500,
    borderRadius: 8,
    paddingVertical: 8,
    paddingHorizontal: 14,
    minWidth: 96,
    alignItems: 'center',
  },
  contributeButtonText: { color: '#ffffff', fontWeight: '600' },
  payoutRow: { flexDirection: 'row', gap: 10, marginTop: 8 },
  payoutButton: {
    backgroundColor: Palette.success,
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 16,
    alignItems: 'center',
  },
  payoutButtonDisabled: { opacity: 0.4 },
  payoutButtonText: { color: '#ffffff', fontWeight: '600' },
  overrideButton: {
    borderWidth: 1,
    borderColor: '#d97706',
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 16,
    alignItems: 'center',
  },
  overrideButtonText: { color: '#d97706', fontWeight: '600' },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
