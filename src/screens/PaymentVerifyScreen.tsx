import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { submitChargeOtp, verifyPaymentIntent } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
import { Button, Icon, Input, Notice, ResultView, Screen } from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import type { PaymentIntent } from '@/types/api';
import { displayFormatted } from '@/utils/money';

const POLL_INTERVAL_MS = 3_000;
const POLL_WINDOW_MS = 90_000;

const TERMINAL_STATUSES: PaymentIntent['status'][] = ['success', 'failed', 'abandoned'];

const STATUS_MESSAGES: Record<PaymentIntent['status'], string> = {
  initiated: 'Starting the payment…',
  pay_offline: 'Approve the prompt on the phone with the mobile money PIN.',
  send_otp: 'Enter the verification code sent to the phone.',
  pending: 'Confirming with the mobile money network…',
  success: 'Payment confirmed!',
  failed: 'Payment failed.',
  abandoned: 'Payment was not completed.',
};

/**
 * Shared by the agent collect screen and the customer deposit screen — same
 * intent lifecycle either way. Polls POST /payments/{id}/verify (not just a
 * passive GET) every ~3s: in dev/LAN deployments Paystack's webhook can't
 * reach the server at all, so the client's own verify call is often the only
 * thing that ever confirms the payment. Webhook and this poll race safely —
 * whichever lands first wins (VerifyPaymentIntentAction.complete).
 */
export default function PaymentVerifyScreen() {
  const theme = useTheme();
  const role = useAuthStore((state) => state.user?.role);
  const { intentId, amountFormatted, customerName } = useLocalSearchParams<{
    intentId: string;
    amountFormatted?: string;
    customerName?: string;
  }>();

  const [intent, setIntent] = useState<PaymentIntent | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [timedOut, setTimedOut] = useState(false);
  const [otp, setOtp] = useState('');
  const [submittingOtp, setSubmittingOtp] = useState(false);

  const stopRef = useRef(false);

  function startPolling() {
    stopRef.current = false;
    setTimedOut(false);
    let elapsed = 0;

    const tick = async () => {
      if (stopRef.current || !intentId) {
        return;
      }

      try {
        const updated = await verifyPaymentIntent(intentId);
        if (stopRef.current) {
          return;
        }

        setIntent(updated);
        setError(null);

        // send_otp and the terminal statuses both need the loop to stop:
        // send_otp waits on user input, the terminal ones are done.
        if (TERMINAL_STATUSES.includes(updated.status) || updated.status === 'send_otp') {
          stopRef.current = true;
          return;
        }
      } catch (err) {
        if (!stopRef.current) {
          setError(apiErrorMessage(err));
        }
      }

      elapsed += POLL_INTERVAL_MS;
      if (elapsed >= POLL_WINDOW_MS) {
        stopRef.current = true;
        setTimedOut(true);
        return;
      }

      if (!stopRef.current) {
        setTimeout(tick, POLL_INTERVAL_MS);
      }
    };

    void tick();
  }

  useEffect(() => {
    // Deferred so startPolling's setTimedOut(false) doesn't run synchronously
    // within the effect body itself.
    const timer = setTimeout(startPolling, 0);

    return () => {
      clearTimeout(timer);
      stopRef.current = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [intentId]);

  async function handleSubmitOtp() {
    if (!intentId || !otp.trim()) {
      return;
    }

    setSubmittingOtp(true);
    setError(null);
    try {
      const updated = await submitChargeOtp(intentId, otp.trim());
      setIntent(updated);
      setOtp('');

      if (!TERMINAL_STATUSES.includes(updated.status)) {
        startPolling();
      }
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmittingOtp(false);
    }
  }

  const status = intent?.status ?? 'initiated';
  const amountText = displayFormatted(amountFormatted ?? intent?.amount_formatted);
  const doneHref = role === 'customer' ? '/(customer)' : '/(agent)';

  if (status === 'success') {
    return (
      <Screen footer={<Button title="Done" size="lg" onPress={() => router.dismissTo(doneHref)} />}>
        <ResultView
          tone="success"
          title="Payment confirmed"
          amount={amountText}
          message={`The mobile money payment${customerName ? ` from ${customerName}` : ''} was approved and recorded by the server.`}
        />
      </Screen>
    );
  }

  if (status === 'failed' || status === 'abandoned') {
    return (
      <Screen
        footer={
          <>
            <Button title="Try again" icon="sync" size="lg" onPress={() => router.back()} />
            <Button title="Cancel" variant="ghost" onPress={() => router.dismissTo(doneHref)} />
          </>
        }
      >
        <ResultView
          tone="failed"
          title={status === 'failed' ? 'Payment failed' : 'Payment not completed'}
          amount={amountText}
          message="No money was taken and nothing was recorded. Check the number and balance, then try again."
        />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        status === 'send_otp' ? (
          <Button
            title="Submit code"
            size="lg"
            loading={submittingOtp}
            loadingTitle="Verifying…"
            disabled={!otp.trim()}
            onPress={handleSubmitOtp}
          />
        ) : timedOut ? (
          <Button title="Check again" icon="sync" size="lg" onPress={startPolling} />
        ) : undefined
      }
    >
      <View style={styles.waiting} accessibilityLiveRegion="polite">
        <View style={[styles.iconCircle, { backgroundColor: theme.infoSoft }]}>
          {timedOut ? <Icon name="clock" size={40} color={theme.info} /> : <ActivityIndicator size="large" color={theme.info} />}
        </View>
        <ThemedText type="caption" themeColor="textMuted">
          Mobile money payment
        </ThemedText>
        <ThemedText type="moneyHero" style={styles.center} adjustsFontSizeToFit numberOfLines={1}>
          {amountText}
        </ThemedText>
        <ThemedText type="bodyStrong" style={styles.center}>
          {timedOut ? 'Still waiting for confirmation' : STATUS_MESSAGES[status]}
        </ThemedText>
        <ThemedText type="small" themeColor="textSecondary" style={styles.center}>
          {timedOut
            ? "Some networks take longer. Don't charge again — check again first so the customer isn't billed twice."
            : 'Keep this screen open. Do not ask for cash until the payment is confirmed.'}
        </ThemedText>
      </View>

      {status === 'send_otp' ? (
        <Input
          label="Verification code"
          value={otp}
          onChangeText={setOtp}
          placeholder="Enter the code sent by SMS"
          keyboardType="number-pad"
          autoComplete="one-time-code"
          textContentType="oneTimeCode"
          autoFocus
        />
      ) : null}

      {error ? <Notice tone="warning" message={error} /> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  waiting: { alignItems: 'center', gap: Spacing.two, paddingTop: Spacing.five },
  iconCircle: {
    width: 88,
    height: 88,
    borderRadius: Radii.pill,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.two,
  },
  center: { textAlign: 'center' },
});
