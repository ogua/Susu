import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';

import { apiErrorMessage } from '@/api/client';
import { chargeMobileMoney } from '@/api/payments';
import { MomoFields } from '@/components/momo-fields';
import { Button, Card, Input, KeyValueRow, Notice, Screen } from '@/components/ui';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useOnline } from '@/hooks/use-network';
import { useAuthStore } from '@/stores/authStore';
import type { MobileMoneyProvider } from '@/types/api';
import { momoPhoneError, normalizeMomoPhone } from '@/utils/momo';
import { formatMoney } from '@/utils/money';

/**
 * Self-service share purchase: routed server-side to BuySharesAction
 * (not RecordCollectionAction) because the account's product type is
 * Shares — see App\Actions\Payments\VerifyPaymentIntentAction::complete().
 */
export default function BuySharesScreen() {
  const online = useOnline();
  const userPhone = useAuthStore((state) => state.user?.phone);
  const { accountId, parValue } = useLocalSearchParams<{ accountId: string; parValue?: string }>();
  const parValueMinorUnits = Number(parValue) || 0;

  const [shares, setShares] = useState('');
  const [phone, setPhone] = useState(userPhone ?? '');
  const [provider, setProvider] = useState<MobileMoneyProvider>('mtn');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [phoneError, setPhoneError] = useState<string | null>(null);
  const submittingRef = useRef(false);
  const { key, rotate } = useIdempotencyKey();

  const sharesCount = /^\d+$/.test(shares) ? Number(shares) : 0;
  const totalAmount = sharesCount * parValueMinorUnits;

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
    if (sharesCount <= 0) {
      setError('Enter how many whole shares you want to buy.');
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
        amount: totalAmount,
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
          title={sharesCount > 0 ? `Pay ${formatMoney(totalAmount)}` : 'Buy shares'}
          icon="phone"
          size="lg"
          loading={submitting}
          loadingTitle="Contacting network…"
          disabled={!online}
          onPress={handleSubmit}
        />
      }
    >
      {!online ? <Notice tone="warning" message="Buying shares needs an internet connection." /> : null}
      <Input
        label="Number of shares"
        icon="chart"
        keyboardType="number-pad"
        value={shares}
        onChangeText={changed((text: string) => setShares(text.replace(/\D/g, '')))}
        placeholder="0"
        error={error}
      />
      <Card>
        <KeyValueRow label="Price per share" value={formatMoney(parValueMinorUnits)} />
        <KeyValueRow label="Shares" value={String(sharesCount)} />
        <KeyValueRow label="Total to pay" value={formatMoney(totalAmount)} emphasis />
      </Card>
      <MomoFields
        phone={phone}
        onPhoneChange={changed(setPhone)}
        provider={provider}
        onProviderChange={changed(setProvider)}
        phoneError={phoneError}
      />
      <Notice tone="info" icon="lock" message="You'll get a prompt on your phone. Approve it with your mobile money PIN." />
    </Screen>
  );
}
