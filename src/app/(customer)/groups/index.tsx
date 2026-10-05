import { router } from 'expo-router';

import { GroupList } from '@/components/group-list';

export default function CustomerGroupsScreen() {
  return (
    <GroupList
      queryKey={['customer', 'groups']}
      onOpen={(group) => router.push({ pathname: '/(customer)/groups/[groupId]', params: { groupId: group.id } })}
      emptyTitle="You're not in a susu group yet"
      emptyHint="Ask your agent about joining a rotating savings group."
    />
  );
}
