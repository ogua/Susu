import { api } from '@/api/client';
import type { Group, Paginated } from '@/types/api';

export async function getGroups(page = 1): Promise<Paginated<Group>> {
  const { data } = await api.get<Paginated<Group>>('/groups', { params: { page } });

  return data;
}

export async function getGroup(groupId: string): Promise<Group> {
  const { data } = await api.get<{ data: Group }>(`/groups/${groupId}`);

  return data.data;
}

export async function recordGroupContribution(
  groupId: string,
  groupMemberId: string,
  clientReference?: string,
): Promise<{ contribution_id: string; amount: number }> {
  const { data } = await api.post<{ contribution_id: string; amount: number }>(
    `/groups/${groupId}/contributions`,
    { group_member_id: groupMemberId, client_reference: clientReference },
  );

  return data;
}

export async function payoutGroupRound(
  roundId: string,
  override = false,
): Promise<{ round_id: string; status: string; payout_entry_id: string | null }> {
  const { data } = await api.post<{ round_id: string; status: string; payout_entry_id: string | null }>(
    `/groups/rounds/${roundId}/payout`,
    { override },
  );

  return data;
}
