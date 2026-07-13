import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { submitChargeOtp, verifyPaymentIntent } from '@/api/payments';
import { ThemedText } from '@/components/themed-text';
import type { PaymentIntent } from '@/types/api';

const POLL_INTERVAL_MS = 3_000;
const POLL_WINDOW_MS = 90_000;

const TERMINAL_STATUSES: PaymentIntent['status'][] = ['success', 'failed', 'abandoned'];

const STATUS_MESSAGES: Record<PaymentIntent['status'], string> = {
  initiated: 'Starting the payment…',
  pay_offline: 'Check your phone and enter your mobile money PIN to approve.',
  send_otp: 'Enter the OTP sent to your phone to continue.',
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
  const { intentId, amountFormatted } = useLocalSearchParams<{
    intentId: string;
    amountFormatted?: string;
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
  const isTerminal = TERMINAL_STATUSES.includes(status);

  return (
    <View style={styles.container}>
      <View style={styles.card}>
        <ThemedText type="subtitle">{amountFormatted ?? intent?.amount_formatted ?? 'Mobile Money Payment'}</ThemedText>

        {!isTerminal && !timedOut ? <ActivityIndicator size="large" style={styles.spinner} /> : null}

        <ThemedText style={status === 'failed' || status === 'abandoned' ? styles.error : undefined}>
          {STATUS_MESSAGES[status]}
        </ThemedText>

        {status === 'send_otp' ? (
          <View style={styles.otpRow}>
            <TextInput
              style={styles.otpInput}
              value={otp}
              onChangeText={setOtp}
              placeholder="OTP"
              keyboardType="number-pad"
              autoFocus
            />
            <Pressable
              style={[styles.button, (submittingOtp || !otp.trim()) && styles.buttonDisabled]}
              onPress={handleSubmitOtp}
              disabled={submittingOtp || !otp.trim()}
            >
              {submittingOtp ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Submit</ThemedText>}
            </Pressable>
          </View>
        ) : null}

        {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

        {timedOut && !isTerminal ? (
          <>
            <ThemedText type="small" style={styles.hint}>
              Still waiting on confirmation. This can take a little longer on some networks.
            </ThemedText>
            <Pressable style={styles.button} onPress={startPolling}>
              <ThemedText style={styles.buttonText}>Check again</ThemedText>
            </Pressable>
          </>
        ) : null}

        {isTerminal ? (
          <Pressable style={styles.button} onPress={() => router.back()}>
            <ThemedText style={styles.buttonText}>Done</ThemedText>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16, justifyContent: 'center' },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 24,
    gap: 14,
    backgroundColor: '#ffffff',
    alignItems: 'center',
  },
  spinner: { marginVertical: 8 },
  otpRow: { flexDirection: 'row', gap: 8, width: '100%' },
  otpInput: {
    flex: 1,
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 18,
    textAlign: 'center',
  },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 20,
    alignItems: 'center',
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700' },
  error: { color: '#d11a2a', textAlign: 'center' },
  hint: { textAlign: 'center', opacity: 0.6 },
});
