import { router } from 'expo-router';

import { GroupList } from '@/components/group-list';

export default function AgentGroupsScreen() {
  return (
    <GroupList
      queryKey={['groups']}
      onOpen={(group) => router.push({ pathname: '/(agent)/groups/[groupId]', params: { groupId: group.id } })}
      emptyTitle="No susu groups yet"
      emptyHint="Groups are set up by your branch on the web. They appear here once created."
    />
  );
}
