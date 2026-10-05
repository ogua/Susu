import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { getLoanGroupHistory, getLoanGroupWithSummary } from '@/api/loanGroups';
import { ThemedText } from '@/components/themed-text';
import {
  Button,
  Card,
  ErrorState,
  KeyValueRow,
  LoadingState,
  Notice,
  Screen,
  SectionHeader,
  StatTile,
  StatTileRow,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { formatDateTime } from '@/utils/format';
import { formatMoney } from '@/utils/money';

/**
 * A customer group at a glance: disbursed / paid / outstanding / overdue,
 * each member's savings and loan account numbers, and the group's history —
 * the mobile counterpart of the web group page.
 */
export default function LoanGroupScreen() {
  const { loanGroupId } = useLocalSearchParams<{ loanGroupId: string }>();
  const group = useQuery({ queryKey: ['agent', 'loanGroup', loanGroupId], queryFn: () => getLoanGroupWithSummary(loanGroupId) });
  const history = useQuery({ queryKey: ['agent', 'loanGroupHistory', loanGroupId], queryFn: () => getLoanGroupHistory(loanGroupId, 50) });

  if (group.isLoading) {
    return <LoadingState label="Loading group…" />;
  }
  if (group.isError || !group.data) {
    return <ErrorState title="Couldn't load this group" onRetry={() => void group.refetch()} />;
  }

  const { group: data, summary } = group.data;
  const members = (data.members ?? []).filter((member) => member.status === 'active');

  return (
    <Screen>
      <ThemedText type="subtitle">{data.name}</ThemedText>
      <ThemedText type="caption" themeColor="textMuted">
        {data.code} · {members.length} active member(s)
      </ThemedText>

      {summary ? (
        <>
          <StatTileRow>
            <StatTile label="Total disbursed" value={formatMoney(summary.total_disbursed)} icon="cash" />
            <StatTile label="Total paid" value={formatMoney(summary.total_paid)} icon="checkCircle" />
          </StatTileRow>
          <StatTileRow>
            <StatTile label="Outstanding" value={formatMoney(summary.outstanding)} icon="loan" />
            <StatTile label="Overdue" value={formatMoney(summary.overdue)} icon="warning" />
          </StatTileRow>
        </>
      ) : (
        <Notice tone="info" message="Update the server to see this group's totals." />
      )}

      <Button
        title="Enter transaction"
        icon="receipt"
        disabled={!data.is_active}
        onPress={() => router.push({ pathname: '/(agent)/collection-sheet', params: { groupId: data.id } })}
      />

      <SectionHeader title="Members" />
      {members.map((member) => {
        const loan = member.open_loan ?? member.active_loan;
        const accounts = (member.savings_accounts ?? []).map((account) => account.account_number).join(', ');

        return (
          <Card key={member.id} style={styles.card}>
            <ThemedText type="heading">{member.customer_name}</ThemedText>
            {member.customer_code || member.customer_phone ? (
              <ThemedText type="caption" themeColor="textMuted">
                {[member.customer_code, member.customer_phone].filter(Boolean).join(' · ')}
              </ThemedText>
            ) : null}
            <KeyValueRow label="Savings account" value={accounts || '—'} />
            <KeyValueRow label="Loan account" value={loan ? `${loan.loan_number} (${loan.status})` : '—'} />
            {loan ? <KeyValueRow label="Loan outstanding" value={formatMoney(loan.outstanding_balance)} /> : null}
          </Card>
        );
      })}

      <SectionHeader title="History" />
      {history.isLoading ? (
        <LoadingState label="Loading history…" />
      ) : (
        <Card style={styles.card}>
          {(history.data ?? []).map((event, index) => (
            <View key={`${event.at}-${index}`} style={styles.event}>
              <ThemedText type="small">{event.description}</ThemedText>
              <ThemedText type="caption" themeColor="textMuted">
                {[formatDateTime(event.at), event.member, event.amount !== null ? formatMoney(event.amount) : null, event.by ? `by ${event.by}` : null]
                  .filter(Boolean)
                  .join(' · ')}
              </ThemedText>
            </View>
          ))}
          {history.data?.length === 0 ? (
            <ThemedText type="small" themeColor="textMuted">
              No activity yet.
            </ThemedText>
          ) : null}
        </Card>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { gap: Spacing.two, marginBottom: Spacing.two },
  event: { gap: 2, paddingVertical: Spacing.one },
});
