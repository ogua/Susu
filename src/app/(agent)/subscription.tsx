import { useQuery, useQueryClient } from '@tanstack/react-query';
import { openBrowserAsync } from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Linking, RefreshControl, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import {
  getSubscription,
  startInvoiceCheckout,
  verifyInvoicePayment,
  type CompanySubscription,
  type OpenInvoice,
  type SubscriptionStatus,
} from '@/api/subscription';
import { ThemedText } from '@/components/themed-text';
import { Badge, Button, Card, ErrorState, KeyValueRow, LoadingState, Notice, ProgressBar, Screen } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { formatDate } from '@/utils/format';
import { formatMoney } from '@/utils/money';

const STATUS: Record<SubscriptionStatus, { label: string; tone: 'success' | 'info' | 'danger' | 'neutral' }> = {
  active: { label: 'Active', tone: 'success' },
  trialing: { label: 'Free trial', tone: 'info' },
  past_due: { label: 'Overdue', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

/**
 * Company admins only: the company's SusuApp plan, usage against its
 * limits, and unpaid invoices they can pay with Paystack. Online only —
 * billing has no offline mode.
 */
export default function SubscriptionScreen() {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [paying, setPaying] = useState<string | null>(null);

  const query = useQuery({ queryKey: ['subscription'], queryFn: getSubscription });

  async function handlePay(invoice: OpenInvoice) {
    setPaying(invoice.id);
    try {
      const checkout = await startInvoiceCheckout(invoice.id);
      await openBrowserAsync(checkout.authorization_url);
      const updated = await verifyInvoicePayment(invoice.id);
      queryClient.setQueryData(['subscription'], updated);

      const stillOpen = updated.open_invoices.some((open) => open.id === invoice.id);
      Alert.alert(
        stillOpen ? 'Payment not confirmed' : 'Payment received',
        stillOpen
          ? 'Paystack has not confirmed this payment yet. If you paid, pull down to refresh in a minute.'
          : `Invoice ${invoice.number} is paid — thank you.`,
      );
    } catch (error) {
      Alert.alert('Could not start the payment', apiErrorMessage(error));
    } finally {
      setPaying(null);
    }
  }

  if (query.isLoading) {
    return <LoadingState label="Loading subscription…" />;
  }

  if (query.isError || !query.data) {
    return <ErrorState onRetry={() => void query.refetch()} />;
  }

  const subscription: CompanySubscription = query.data;
  const status = subscription.status ? STATUS[subscription.status] : null;
  const support = subscription.support;

  return (
    <Screen refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} tintColor={theme.primary} />}>
      <Card style={styles.card}>
        <ThemedText type="caption" themeColor="textSecondary">
          Current plan
        </ThemedText>
        {subscription.plan ? (
          <>
            <View style={styles.row}>
              <ThemedText type="heading" style={styles.flex}>
                {subscription.plan.name}
              </ThemedText>
              {status ? <Badge label={status.label} tone={status.tone} /> : null}
            </View>
            <KeyValueRow
              label="Price"
              value={`${formatMoney(subscription.plan.price_amount)} / ${subscription.plan.billing_period === 'yearly' ? 'year' : 'month'}`}
            />
            <KeyValueRow
              label={subscription.trial_ends_at ? 'Trial ends' : 'Renews'}
              value={formatDate(subscription.trial_ends_at ?? subscription.current_period_end)}
            />
          </>
        ) : (
          <ThemedText type="small" themeColor="textSecondary">
            Your company is not on a subscription plan yet. Contact the SusuApp team to choose one.
          </ThemedText>
        )}
      </Card>

      <Card style={styles.card}>
        <ThemedText type="bodyStrong">Usage</ThemedText>
        {(['branches', 'staff', 'customers'] as const).map((resource) => {
          const figure = subscription.usage[resource];

          return (
            <View key={resource} style={styles.usage}>
              <View style={styles.row}>
                <ThemedText type="small" style={styles.flex}>
                  {resource.charAt(0).toUpperCase() + resource.slice(1)}
                </ThemedText>
                <ThemedText type="small" themeColor="textSecondary">
                  {figure.used} / {figure.limit ?? 'Unlimited'}
                </ThemedText>
              </View>
              {figure.limit ? (
                <ProgressBar
                  progress={figure.used / figure.limit}
                  color={figure.used / figure.limit >= 0.9 ? theme.danger : theme.primary}
                  accessibilityLabel={`${resource}: ${figure.used} of ${figure.limit}`}
                />
              ) : null}
            </View>
          );
        })}
      </Card>

      <ThemedText type="bodyStrong" style={styles.sectionTitle}>
        Unpaid invoices
      </ThemedText>
      {subscription.open_invoices.length === 0 ? (
        <Notice tone="success" message="Nothing to pay — you're up to date." />
      ) : (
        subscription.open_invoices.map((invoice) => (
          <Card key={invoice.id} style={styles.card}>
            <View style={styles.row}>
              <ThemedText type="bodyStrong" style={styles.flex}>
                {invoice.number}
              </ThemedText>
              {invoice.is_overdue ? <Badge label="Overdue" tone="danger" /> : <Badge label="Due" tone="warning" />}
            </View>
            <KeyValueRow label="Amount" value={formatMoney(invoice.amount)} emphasis />
            <KeyValueRow label="Period" value={`${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}`} />
            <KeyValueRow label="Due" value={formatDate(invoice.due_at)} />
            <Button
              title="Pay with Paystack"
              loadingTitle="Opening Paystack…"
              icon="cash"
              loading={paying === invoice.id}
              disabled={paying !== null}
              onPress={() => void handlePay(invoice)}
            />
          </Card>
        ))
      )}

      {support?.email || support?.phone ? (
        <View style={styles.support}>
          <ThemedText type="caption" themeColor="textSecondary">
            Questions about your subscription? Contact the SusuApp team.
          </ThemedText>
          <View style={styles.row}>
            {support.email ? (
              <Button title="Email" variant="ghost" icon="document" onPress={() => void Linking.openURL(`mailto:${support.email}`)} />
            ) : null}
            {support.phone ? (
              <Button title="Call" variant="ghost" icon="phone" onPress={() => void Linking.openURL(`tel:${support.phone}`)} />
            ) : null}
          </View>
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  card: { gap: Spacing.two, marginBottom: Spacing.three },
  row: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  usage: { gap: Spacing.one },
  sectionTitle: { marginBottom: Spacing.two },
  support: { gap: Spacing.one, marginTop: Spacing.two },
});
