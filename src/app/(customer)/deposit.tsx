import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { MomoFields } from '@/components/momo-fields';
import { AmountInput, Button, Notice, Screen } from '@/components/ui';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useOnline } from '@/hooks/use-network';
import { useAuthStore } from '@/stores/authStore';
import type { MobileMoneyProvider } from '@/types/api';
import { momoPhoneError, normalizeMomoPhone } from '@/utils/momo';
import { formatMoney, parseAmountToMinor } from '@/utils/money';

/** Self-service deposit: RecordCollectionAction allows a customer to pay into their own account. */
export default function DepositScreen() {
  const online = useOnline();
  const userPhone = useAuthStore((state) => state.user?.phone);
  const { accountId, accountNumber } = useLocalSearchParams<{ accountId: string; accountNumber?: string }>();

  const [amount, setAmount] = useState('');
  const [phone, setPhone] = useState(userPhone ?? '');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [phoneError, setPhoneError] = useState<string | null>(null);
  const submittingRef = useRef(false);
  const { key, rotate } = useIdempotencyKey();

  const amountMinor = parseAmountToMinor(amount);

  function changed<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      rotate();
    };
  }

  async function handleSubmit() {
    if (submittingRef.current) {
      return;
    }
    setError(null);
    setPhoneError(null);
    if (amountMinor === null || amountMinor <= 0) {
      setError('Enter the amount you want to deposit.');
      return;
    }
    const invalidPhone = momoPhoneError(phone);
    if (invalidPhone) {
      setPhoneError(invalidPhone);
      return;
    }

    submittingRef.current = true;
    setSubmitting(true);
    try {
      const intent = await chargeMobileMoney({
        savings_account_id: accountId,
        amount: amountMinor,
        phone: normalizeMomoPhone(phone),
        provider,
        client_reference: key,
      });
      rotate();

      router.push({
        pathname: '/(customer)/payment-verify',
        params: { intentId: intent.id, amountFormatted: intent.amount_formatted },
      });
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  return (
    <Screen
      footer={
        <Button
          title={amountMinor ? `Pay ${formatMoney(amountMinor)}` : 'Pay with mobile money'}
          icon="phone"
          size="lg"
          loading={submitting}
          loadingTitle="Contacting network…"
          disabled={!online}
          onPress={handleSubmit}
        />
      }
    >
      {accountNumber ? <Notice tone="info" message={`Depositing into account ${accountNumber}.`} icon="wallet" /> : null}
      {!online ? <Notice tone="warning" message="Mobile money payments need an internet connection." /> : null}
      <AmountInput label="Amount to deposit" value={amount} onChangeText={changed(setAmount)} error={error} />
      <MomoFields
        phone={phone}
        onPhoneChange={changed(setPhone)}
        provider={provider}
        onProviderChange={changed(setProvider)}
        phoneError={phoneError}
      />
      <Notice tone="info" icon="lock" message="You'll get a prompt on your phone. Approve it with your mobile money PIN — never share your PIN with anyone." />
    </Screen>
  );
}
