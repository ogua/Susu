import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, View } from 'react-native';

import { getGroups } from '@/api/groups';
import { ThemedText } from '@/components/themed-text';
import type { Group } from '@/types/api';
import { Palette } from '@/constants/theme';

export default function CustomerGroupsScreen() {
  const groups = useQuery({
    queryKey: ['customer', 'groups'],
    queryFn: () => getGroups(),
  });

  function renderItem({ item }: { item: Group }) {
    return (
      <Pressable
        style={styles.row}
        onPress={() => router.push({ pathname: '/(customer)/groups/[groupId]', params: { groupId: item.id } })}
      >
        <View style={{ flex: 1 }}>
          <ThemedText type="smallBold">{item.name}</ThemedText>
          <ThemedText type="small">{item.contribution_amount_formatted} / {item.frequency}</ThemedText>
        </View>
        <ThemedText type="small" style={styles.status}>{item.status}</ThemedText>
      </Pressable>
    );
  }

  return (
    <View style={styles.container}>
      {groups.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : groups.isError ? (
        <ThemedText style={styles.empty}>Could not load your groups.</ThemedText>
      ) : (
        <FlatList
          data={groups.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          ItemSeparatorComponent={() => <View style={styles.separator} />}
          onRefresh={() => groups.refetch()}
          refreshing={groups.isRefetching}
          ListEmptyComponent={<ThemedText style={styles.empty}>You are not a member of any susu group yet.</ThemedText>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 14 },
  status: { textTransform: 'capitalize', opacity: 0.7 },
  separator: { height: 1, backgroundColor: Palette.border },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
