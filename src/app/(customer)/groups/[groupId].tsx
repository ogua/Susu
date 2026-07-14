import { useQuery } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, ScrollView, StyleSheet, View } from 'react-native';

import { getGroup } from '@/api/groups';
import { ThemedText } from '@/components/themed-text';

export default function CustomerGroupDetailScreen() {
  const { groupId } = useLocalSearchParams<{ groupId: string }>();

  const group = useQuery({
    queryKey: ['customer', 'group', groupId],
    queryFn: () => getGroup(groupId),
    enabled: !!groupId,
  });

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
        <ThemedText type="small">{group.data.contribution_amount_formatted} per {group.data.frequency.replace('ly', '')}</ThemedText>
        <ThemedText type="small" style={styles.status}>{group.data.status}</ThemedText>
      </View>

      <ThemedText type="subtitle" style={styles.sectionTitle}>Rotation Order</ThemedText>
      {(group.data.members ?? []).map((member) => (
        <View key={member.id} style={styles.row}>
          <ThemedText>#{member.rotation_position} {member.customer_name}</ThemedText>
        </View>
      ))}

      <ThemedText type="subtitle" style={styles.sectionTitle}>Rounds</ThemedText>
      {(group.data.rounds ?? []).map((round) => (
        <View key={round.id} style={styles.card}>
          <ThemedText type="smallBold">Round {round.round_number} — {round.payout_member?.customer_name}</ThemedText>
          <ThemedText type="small">Due {new Date(round.due_date).toLocaleDateString()}</ThemedText>
          <ThemedText>{round.total_collected_formatted} / {round.total_expected_formatted} collected</ThemedText>
          <ThemedText type="small" style={styles.status}>{round.status}</ThemedText>
        </View>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 10 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 4,
    backgroundColor: '#ffffff',
  },
  status: { textTransform: 'capitalize', opacity: 0.7 },
  sectionTitle: { marginTop: 8 },
  row: {
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#e5e5ea',
  },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
