import { api } from '@/api/client';
import type { AgentPositionsResponse, AgentRoute } from '@/types/api';

/** Live positions of the manager's branch agents (same list as the web map). */
export async function getAgentPositions(): Promise<AgentPositionsResponse> {
  const { data } = await api.get<AgentPositionsResponse>('/agents/positions');

  return data;
}

/** One agent's route, stops and geotagged collections for a day (YYYY-MM-DD). */
export async function getAgentRoute(agentId: string, date: string): Promise<AgentRoute> {
  const { data } = await api.get<{ data: AgentRoute }>(`/agents/${agentId}/route`, { params: { date } });

  return data.data;
}
