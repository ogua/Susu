import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { openBrowserAsync } from 'expo-web-browser';
import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, StyleSheet, View } from 'react-native';

import { getAccountTransactions, getCustomerStatementUrl } from '@/api/accounts';
import { apiErrorMessage } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, EmptyState, ListRow, ProgressBar, Screen, StatTile } from '@/components/ui';
import { Palette } from '@/constants/theme';
import type { SavingsProductType, Transaction } from '@/types/api';

const TYPE_LABELS: Record<Transaction['type'], string> = {
  collection: 'Deposit',
  commission: 'Commission',
  withdrawal: 'Withdrawal',
  remittance: 'Remittance',
  reversal: 'Correction',
  adjustment: 'Adjustment',
};

export default function AccountDetailScreen() {
  const {
    accountId,
    productType,
    targetAmountFormatted,
    targetProgressPercent,
    maturesAt,
    maturedAt,
    parValue,
    parValueFormatted,
    shareCount,
  } = useLocalSearchParams<{
    accountId: string;
    productType?: SavingsProductType | '';
    targetAmountFormatted?: string;
    targetProgressPercent?: string;
    maturesAt?: string;
    maturedAt?: string;
    parValue?: string;
    parValueFormatted?: string;
    shareCount?: string;
  }>();

  const isFixedDeposit = productType === 'fixed_deposit';
  const isShares = productType === 'shares';

  const transactions = useQuery({
    queryKey: ['customer', 'account', accountId, 'transactions'],
    queryFn: () => getAccountTransactions(accountId),
    enabled: !!accountId,
  });

  const [downloadingStatement, setDownloadingStatement] = useState(false);

  async function handleDownloadStatement() {
    setDownloadingStatement(true);
    try {
      const url = await getCustomerStatementUrl(accountId);
      await openBrowserAsync(url);
    } catch (err) {
      Alert.alert('Could not open statement', apiErrorMessage(err));
    } finally {
      setDownloadingStatement(false);
    }
  }

  function renderItem({ item }: { item: Transaction }) {
    const isDebit = item.type === 'withdrawal';

    return (
      <ListRow
        title={TYPE_LABELS[item.type] ?? item.type}
        subtitle={new Date(item.recorded_at).toLocaleString()}
        right={
          <ThemedText style={{ color: isDebit ? Palette.danger : Palette.success, fontWeight: '700' }}>
            {isDebit ? '-' : '+'}
            {item.amount_formatted}
          </ThemedText>
        }
      />
    );
  }

  return (
    <Screen scroll={false}>
      {targetAmountFormatted ? (
        <Card>
          <ThemedText type="smallBold">Target: {targetAmountFormatted}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {targetProgressPercent}% saved
            {maturedAt ? ' · Matured — no early-withdrawal penalty' : maturesAt ? ` · Matures ${new Date(maturesAt).toLocaleDateString()}` : ''}
          </ThemedText>
          <ProgressBar progress={(Number(targetProgressPercent) || 0) / 100} />
        </Card>
      ) : null}

      {isFixedDeposit ? (
        <Card>
          <ThemedText type="smallBold">Fixed deposit</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {maturedAt
              ? 'Matured — available to withdraw'
              : maturesAt
                ? `Matures ${new Date(maturesAt).toLocaleDateString()} · cannot withdraw before this date`
                : ''}
          </ThemedText>
        </Card>
      ) : null}

      {isShares ? <StatTile label="Shares owned" value={shareCount ?? '0'} /> : null}

      <View style={styles.actionRow}>
        <Button
          title="Deposit via Mobile Money"
          onPress={() => router.push({ pathname: '/(customer)/deposit', params: { accountId } })}
        />
        <Button
          title="Request Withdrawal"
          variant="secondary"
          onPress={() =>
            router.push({
              pathname: '/(customer)/withdraw',
              params: { accountId, productType: productType ?? '', maturesAt: maturesAt ?? '', maturedAt: maturedAt ?? '' },
            })
          }
        />
        {isShares ? (
          <Button
            title="Buy Shares"
            variant="secondary"
            onPress={() =>
              router.push({
                pathname: '/(customer)/buy-shares',
                params: { accountId, parValue: parValue ?? '', parValueFormatted: parValueFormatted ?? '' },
              })
            }
          />
        ) : null}
        <Button
          title={downloadingStatement ? 'Opening…' : 'Download Statement'}
          variant="ghost"
          disabled={downloadingStatement}
          onPress={handleDownloadStatement}
        />
      </View>

      {transactions.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : transactions.isError ? (
        <EmptyState title="Could not load transactions" hint="Pull down to retry." />
      ) : (
        <FlatList
          data={transactions.data?.data ?? []}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          onRefresh={() => transactions.refetch()}
          refreshing={transactions.isRefetching}
          ListEmptyComponent={<EmptyState title="No transactions yet" />}
        />
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  actionRow: { gap: 8 },
});
