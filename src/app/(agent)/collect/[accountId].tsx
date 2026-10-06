import { router, useLocalSearchParams } from 'expo-router';
import { openBrowserAsync } from 'expo-web-browser';
import { useEffect, useRef, useState } from 'react';
import { Alert, Share, StyleSheet, View } from 'react-native';

import { getAgentStatementUrl } from '@/api/accounts';
import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { MomoFields } from '@/components/momo-fields';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Avatar,
  Badge,
  Button,
  Card,
  Field,
  KeyValueRow,
  Notice,
  ReceiptCard,
  ResultView,
  Screen,
  SegmentedControl,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useOnline } from '@/hooks/use-network';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { drainOutbox } from '@/sync/engine';
import { enqueueCollection } from '@/sync/ops';
import { getOutboxItem, type OutboxStatus } from '@/sync/outbox';
import type { MobileMoneyProvider } from '@/types/api';
import { formatDateTime } from '@/utils/format';
import { getCurrentPositionSafe } from '@/utils/location';
import { momoPhoneError, normalizeMomoPhone } from '@/utils/momo';
import { displayFormatted, formatMoney, minorToInput, parseAmountToMinor } from '@/utils/money';

type Method = 'cash' | 'mobile_money';

interface SavedCollection {
  opId: string;
  amount: number;
  recordedAt: Date;
}

/**
 * Cash stays offline-first (outbox); mobile money needs a live round trip to
 * Paystack, so it bypasses the outbox entirely and goes straight to the
 * verify screen once the charge is initiated.
 *
 * Double-submit safety: a ref guard blocks re-entry within the same frame,
 * and once a cash collection is queued the form is replaced by the receipt —
 * there is no button left that could queue it a second time.
 */
export default function CollectScreen() {
  const theme = useTheme();
  const online = useOnline();
  const user = useAuthStore((state) => state.user);
  const params = useLocalSearchParams<{
    accountId: string;
    accountNumber?: string;
    customerName?: string;
    customerId?: string;
    customerPhone?: string;
    productName?: string;
    contributionAmount?: string;
    productType?: string;
    balance?: string;
    maturedAt?: string;
    balanceFormatted?: string;
    status?: string;
  }>();

  const agreed = Number(params.contributionAmount) || 0;
  // Mirrors RecordCollectionAction: shares are bought, not collected, and a
  // fixed deposit takes exactly its principal once, before it matures.
  const isFixedDeposit = params.productType === 'fixed_deposit';
  const blockedReason =
    params.productType === 'shares'
      ? 'Share accounts are funded by buying shares, not by collections.'
      : isFixedDeposit && ((Number(params.balance) || 0) > 0 || !!params.maturedAt)
        ? 'This fixed deposit is already funded and cannot take further deposits.'
        : null;
  const [method, setMethod] = useState<Method>('cash');
  const [amount, setAmount] = useState(agreed > 0 ? minorToInput(agreed) : '');
  const [phone, setPhone] = useState(params.customerPhone ?? '');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [phoneError, setPhoneError] = useState<string | null>(null);
  const [saved, setSaved] = useState<SavedCollection | null>(null);
  const [openingStatement, setOpeningStatement] = useState(false);
  const submittingRef = useRef(false);
  const momoKey = useIdempotencyKey();

  const amountMinor = parseAmountToMinor(amount);
  const customerName = params.customerName || 'Customer';
  const quickAmounts = agreed > 0 ? (isFixedDeposit ? [agreed] : [agreed, agreed * 2, agreed * 5, agreed * 10]) : undefined;

  function changeMomoInput<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      // A changed amount/number/network is a new payment attempt.
      momoKey.rotate();
    };
  }

  async function submitCash(minor: number) {
    const location = await getCurrentPositionSafe();
    const opId = await enqueueCollection({
      savings_account_id: params.accountId,
      amount: minor,
      latitude: location?.latitude,
      longitude: location?.longitude,
    });

    setSaved({ opId, amount: minor, recordedAt: new Date() });
    // Fire-and-forget: never block the agent's next collection on network.
    void drainOutbox();
  }

  async function submitMobileMoney(minor: number) {
    const intent = await chargeMobileMoney({
      savings_account_id: params.accountId,
      amount: minor,
      phone: normalizeMomoPhone(phone),
      provider,
      client_reference: momoKey.key,
    });
    momoKey.rotate();

    router.push({
      pathname: '/(agent)/payment-verify',
      params: { intentId: intent.id, amountFormatted: intent.amount_formatted, customerName },
    });
  }

  async function handleSubmit() {
    if (submittingRef.current || saved) {
      return;
    }
    setError(null);
    setPhoneError(null);

    if (blockedReason) {
      setError(blockedReason);
      return;
    }
    if (amountMinor === null || amountMinor <= 0) {
      setError('Enter the amount the customer is paying.');
      return;
    }
    if (isFixedDeposit && amountMinor !== agreed) {
      setError(`A fixed deposit must be funded with exactly its principal of ${formatMoney(agreed)}.`);
      return;
    }
    if (method === 'mobile_money') {
      const invalid = momoPhoneError(phone);
      if (invalid) {
        setPhoneError(invalid);
        return;
      }
    }

    submittingRef.current = true;
    setSubmitting(true);
    try {
      if (method === 'cash') {
        await submitCash(amountMinor);
      } else {
        await submitMobileMoney(amountMinor);
      }
    } catch (err) {
      setError(
        method === 'cash'
          ? "This collection couldn't be saved on the phone. Nothing was recorded — please try again."
          : apiErrorMessage(err),
      );
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  async function handleStatement() {
    setOpeningStatement(true);
    try {
      await openBrowserAsync(await getAgentStatementUrl(params.accountId));
    } catch (err) {
      Alert.alert("Couldn't open the statement", apiErrorMessage(err));
    } finally {
      setOpeningStatement(false);
    }
  }

  if (saved) {
    return (
      <CollectionResult
        saved={saved}
        customerName={customerName}
        accountNumber={params.accountNumber}
        productName={params.productName}
        collector={user?.name}
        business={user?.company?.name}
      />
    );
  }

  const submitTitle =
    amountMinor && amountMinor > 0
      ? method === 'cash'
        ? `Record ${formatMoney(amountMinor)} cash`
        : `Charge ${formatMoney(amountMinor)} via MoMo`
      : method === 'cash'
        ? 'Record cash collection'
        : 'Charge mobile money';

  return (
    <Screen
      footer={
        <>
          <Button
            title={submitTitle}
            loadingTitle={method === 'cash' ? 'Saving…' : 'Contacting network…'}
            icon={method === 'cash' ? 'cash' : 'phone'}
            size="lg"
            loading={submitting}
            disabled={!!blockedReason || (method === 'mobile_money' && !online)}
            onPress={handleSubmit}
          />
          <ThemedText type="caption" themeColor="textMuted" style={styles.center}>
            {method === 'cash'
              ? 'Saves on this phone instantly, even offline, then syncs automatically.'
              : online
                ? 'The customer approves the payment with their MoMo PIN.'
                : 'Mobile money needs an internet connection.'}
          </ThemedText>
        </>
      }
    >
      <Card>
        <View style={styles.customerRow}>
          <Avatar name={customerName} size={48} />
          <View style={styles.flex}>
            <ThemedText type="heading" numberOfLines={1}>
              {customerName}
            </ThemedText>
            <ThemedText type="small" themeColor="textSecondary" numberOfLines={1}>
              {params.accountNumber}
              {params.productName ? ` · ${params.productName}` : ''}
            </ThemedText>
          </View>
          {params.status && params.status !== 'active' ? <Badge label={params.status} /> : null}
        </View>
        <View style={[styles.divider, { backgroundColor: theme.border }]} />
        <KeyValueRow label="Current savings balance" value={displayFormatted(params.balanceFormatted)} emphasis />
        {agreed > 0 ? <KeyValueRow label={isFixedDeposit ? 'Principal' : 'Agreed contribution'} value={formatMoney(agreed)} /> : null}
        <View style={styles.secondaryActions}>
          <Button
            title="Statement"
            icon="document"
            variant="outline"
            loading={openingStatement}
            onPress={handleStatement}
            style={styles.flex}
          />
          <Button
            title="Apply for loan"
            icon="loan"
            variant="outline"
            style={styles.flex}
            onPress={() =>
              router.push({
                pathname: '/(agent)/loans/apply/[accountId]',
                params: { accountId: params.accountId, customerId: params.customerId ?? '', customerName },
              })
            }
          />
        </View>
      </Card>

      {blockedReason ? <Notice tone="warning" message={blockedReason} /> : null}

      {params.status && params.status !== 'active' ? (
        <Notice
          tone="warning"
          message={`This account is ${params.status}. The server may not accept collections on it.`}
        />
      ) : null}

      <Field label="Payment method">
        <SegmentedControl<Method>
          accessibilityLabel="Payment method"
          options={[
            { value: 'cash', label: 'Cash', icon: 'cash' },
            { value: 'mobile_money', label: 'Mobile Money', icon: 'phone' },
          ]}
          value={method}
          onChange={setMethod}
        />
      </Field>

      <AmountInput
        label="Amount received"
        value={amount}
        onChangeText={changeMomoInput(setAmount)}
        quickAmounts={quickAmounts}
        error={error}
        returnKeyType="done"
        selectTextOnFocus
      />

      {method === 'mobile_money' ? (
        <MomoFields
          phoneLabel="Customer's MoMo number"
          phone={phone}
          onPhoneChange={changeMomoInput(setPhone)}
          provider={provider}
          onProviderChange={changeMomoInput(setProvider)}
          phoneError={phoneError}
        />
      ) : null}
    </Screen>
  );
}

/**
 * Post-save receipt. Reports exactly what is known: the record is on the
 * phone; it flips to "synced" only when the outbox drain confirms it. We do
 * not show a new balance — that's computed server-side (commission etc.).
 */
function CollectionResult({
  saved,
  customerName,
  accountNumber,
  productName,
  collector,
  business,
}: {
  saved: SavedCollection;
  customerName: string;
  accountNumber?: string;
  productName?: string;
  collector?: string;
  business?: string;
}) {
  const [status, setStatus] = useState<OutboxStatus>('pending');
  const [rejection, setRejection] = useState<string | null>(null);
  const [server, setServer] = useState<ServerCollectionResult | null>(null);
  const pendingCount = useOutboxStatus((state) => state.pending);
  const syncing = useOutboxStatus((state) => state.syncing);

  // Re-read this op whenever the shared outbox state changes (a drain finished).
  useEffect(() => {
    let active = true;
    void getOutboxItem(saved.opId).then((item) => {
      if (active && item) {
        setStatus(item.status);
        setRejection(item.last_error);
        setServer(parseServerResult(item.result));
      }
    });

    return () => {
      active = false;
    };
  }, [saved.opId, pendingCount, syncing]);

  const deviceReference = saved.opId.slice(0, 8).toUpperCase();
  const reference = server?.reference ?? deviceReference;

  async function shareReceipt() {
    // Plain text so it works in WhatsApp, SMS and email alike. States the
    // sync status honestly — an unsynced receipt says it is pending.
    const lines = [
      `${business || 'OguaFinance'} — Collection receipt`,
      `Customer: ${customerName}`,
      `Account: ${accountNumber ?? '—'}${productName ? ` (${productName})` : ''}`,
      `Amount: ${formatMoney(saved.amount)} (Cash)`,
      `Date: ${formatDateTime(saved.recordedAt)}`,
      collector ? `Collected by: ${collector}` : null,
      `Reference: ${reference}`,
      server?.commission ? `Commission: ${formatMoney(server.commission)}` : null,
      server?.balance !== undefined ? `New savings balance: ${formatMoney(server.balance)}` : null,
      status === 'synced' ? 'Status: Confirmed' : 'Status: Pending confirmation',
    ];
    try {
      await Share.share({ message: lines.filter(Boolean).join('\n') });
    } catch {
      // User dismissed or no share target — nothing to do.
    }
  }
  const tone = status === 'synced' ? 'success' : status === 'rejected' ? 'failed' : 'pending';
  const title =
    status === 'synced'
      ? 'Collection recorded'
      : status === 'rejected'
        ? 'Collection needs attention'
        : 'Saved on this phone';
  const message =
    status === 'synced'
      ? `The server has confirmed this collection for ${customerName}.`
      : status === 'rejected'
        ? "The server didn't accept this collection, so the customer's balance has not changed. Open Sync Status to review it."
        : syncing
          ? 'Sending to the server now…'
          : 'Securely saved. It will sync automatically when you have a connection — you can carry on collecting.';

  return (
    <Screen
      footer={
        <>
          <Button title="Next customer" icon="search" size="lg" onPress={() => router.back()} />
          {status !== 'rejected' ? (
            <Button title="Share receipt" variant="outline" icon="send" onPress={shareReceipt} />
          ) : null}
          {status === 'rejected' ? (
            <Button title="Open Sync Status" variant="outline" icon="sync" onPress={() => router.replace('/(agent)/sync')} />
          ) : (
            <Button title="Back to home" variant="ghost" onPress={() => router.dismissTo('/(agent)')} />
          )}
        </>
      }
    >
      <ResultView tone={tone} title={title} amount={formatMoney(saved.amount)} message={message}>
        <ReceiptCard
          business={business}
          footnote={
            status === 'synced'
              ? 'Confirmed by the server.'
              : 'Pending server confirmation. Keep this reference until it syncs.'
          }
        >
          <KeyValueRow label="Customer" value={customerName} />
          <KeyValueRow label="Account" value={`${accountNumber ?? '—'}${productName ? ` · ${productName}` : ''}`} />
          <KeyValueRow label="Amount" value={formatMoney(saved.amount)} emphasis />
          <KeyValueRow label="Payment method" value="Cash" />
          <KeyValueRow label="Date & time" value={formatDateTime(saved.recordedAt)} />
          {collector ? <KeyValueRow label="Collected by" value={collector} /> : null}
          {server?.reference ? (
            <KeyValueRow label="Receipt number" value={server.reference} />
          ) : (
            <KeyValueRow label="Device reference" value={deviceReference} />
          )}
          {server?.commission ? <KeyValueRow label="Commission charged" value={formatMoney(server.commission)} /> : null}
          {server?.balance !== undefined ? (
            <KeyValueRow label="New savings balance" value={formatMoney(server.balance)} emphasis />
          ) : null}
          <View style={styles.statusRow}>
            <ThemedText type="small" themeColor="textSecondary">
              Status
            </ThemedText>
            <Badge
              label={status === 'synced' ? 'Synced' : status === 'rejected' ? 'Rejected' : 'Waiting to sync'}
              tone={status === 'synced' ? 'success' : status === 'rejected' ? 'danger' : 'warning'}
            />
          </View>
          {rejection && status === 'rejected' ? <Notice tone="danger" message={friendlyReason(rejection)} /> : null}
        </ReceiptCard>
      </ResultView>
    </Screen>
  );
}

interface ServerCollectionResult {
  reference?: string;
  balance?: number;
  commission?: number;
}

/** The sync batch's result for collection.record: { reference, balance, commission, … }. */
function parseServerResult(raw: string | null): ServerCollectionResult | null {
  if (!raw) {
    return null;
  }
  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>;

    return {
      reference: typeof parsed.reference === 'string' ? parsed.reference : undefined,
      balance: typeof parsed.balance === 'number' ? parsed.balance : undefined,
      commission: typeof parsed.commission === 'number' ? parsed.commission : undefined,
    };
  } catch {
    return null;
  }
}

/** Server rejection reasons are validation messages; hide anything technical. */
function friendlyReason(error: string): string {
  return /exception|sql|stack|http|\bat\s|undefined|null/i.test(error) || error.length > 200
    ? 'The server could not process this record.'
    : error;
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  center: { textAlign: 'center' },
  customerRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  divider: { height: StyleSheet.hairlineWidth, marginVertical: Spacing.one },
  secondaryActions: { flexDirection: 'row', gap: Spacing.two, marginTop: Spacing.one },
  statusRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 6 },
});
