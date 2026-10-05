import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { applyForLoan, getLoanEligibility, getLoanProducts } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Button,
  Card,
  ChipSelect,
  ErrorState,
  Field,
  Input,
  LoadingState,
  Notice,
  ResultView,
  Screen,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useIdempotencyKey } from '@/hooks/use-idempotency-key';
import { useTheme } from '@/hooks/use-theme';
import type { SavingsAccount } from '@/types/api';
import { displayFormatted, formatMoney, parseAmountToMinor } from '@/utils/money';

/**
 * Loan application shared by staff (on behalf of a customer, with
 * guarantor) and customers (self-service). Eligibility is the server's call;
 * this only displays it as the amount is typed.
 */
export function LoanApplicationForm({
  customerId,
  customerName,
  accounts,
  fixedAccountId,
  withGuarantor,
  onDone,
}: {
  customerId?: string;
  customerName?: string;
  /** Customer self-service: choose among their accounts. */
  accounts?: SavingsAccount[];
  /** Staff: application is tied to the account they came from. */
  fixedAccountId?: string;
  withGuarantor: boolean;
  onDone: () => void;
}) {
  const theme = useTheme();
  const products = useQuery({ queryKey: ['loan-products'], queryFn: getLoanProducts });

  const [selectedAccountId, setSelectedAccountId] = useState<string | null>(null);
  const [productId, setProductId] = useState<string | null>(null);
  const [amount, setAmount] = useState('');
  const [guarantorName, setGuarantorName] = useState('');
  const [guarantorPhone, setGuarantorPhone] = useState('');
  const [eligible, setEligible] = useState<boolean | null>(null);
  const [reasons, setReasons] = useState<string[]>([]);
  const [checking, setChecking] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState<number | null>(null);
  const submittingRef = useRef(false);
  const { key, rotate } = useIdempotencyKey();

  // Defaults to the first account until the customer explicitly picks one.
  const accountId = fixedAccountId ?? selectedAccountId ?? accounts?.[0]?.id ?? null;
  const product = products.data?.find((item) => item.id === productId) ?? null;
  const amountMinor = parseAmountToMinor(amount);

  useEffect(() => {
    const valid = !!amountMinor && amountMinor > 0 && !!accountId;
    // A 0ms defer keeps setState out of the effect body (lint rule); the
    // 500ms debounce is the real delay for the eligibility API call.
    const resetTimer = !valid
      ? setTimeout(() => {
          setEligible(null);
          setReasons([]);
        }, 0)
      : undefined;

    const checkTimer =
      valid && accountId
        ? setTimeout(() => {
            setChecking(true);
            getLoanEligibility(accountId, amountMinor)
              .then((result) => {
                setEligible(result.eligible);
                setReasons(result.reasons);
              })
              .catch(() => {
                setEligible(null);
                setReasons([]);
              })
              .finally(() => setChecking(false));
          }, 500)
        : undefined;

    return () => {
      clearTimeout(resetTimer);
      clearTimeout(checkTimer);
    };
  }, [amountMinor, accountId]);

  async function handleSubmit() {
    if (submittingRef.current || submitted !== null) return;
    setError(null);
    if (!product) {
      setError('Choose a loan product.');
      return;
    }
    if (!amountMinor) {
      setError('Enter the amount to borrow.');
      return;
    }

    submittingRef.current = true;
    setSubmitting(true);
    try {
      await applyForLoan({
        customer_id: customerId,
        loan_product_id: product.id,
        amount: amountMinor,
        savings_account_id: accountId ?? undefined,
        guarantor_name: guarantorName.trim() || undefined,
        guarantor_phone: guarantorPhone.trim() || undefined,
        client_reference: key,
      });
      setSubmitted(amountMinor);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  if (submitted !== null) {
    return (
      <Screen footer={<Button title="View loans" size="lg" onPress={onDone} />}>
        <ResultView
          tone="success"
          title="Application submitted"
          amount={formatMoney(submitted)}
          message="The branch will review it. No money is disbursed until the loan is approved."
        />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <Button
          title={amountMinor ? `Apply for ${formatMoney(amountMinor)}` : 'Submit application'}
          icon="send"
          size="lg"
          loading={submitting}
          loadingTitle="Submitting…"
          onPress={handleSubmit}
        />
      }
    >
      {customerName ? (
        <Notice tone="info" icon="person" message={`Applying on behalf of ${customerName}.`} />
      ) : null}

      {accounts && accounts.length > 1 ? (
        <Field label="Linked savings account">
          <ChipSelect
            accessibilityLabel="Linked savings account"
            options={accounts.map((account) => ({
              value: account.id,
              label: account.account_number,
              description: `Balance ${displayFormatted(account.balance_formatted)}`,
            }))}
            value={accountId}
            onChange={(value) => {
              setSelectedAccountId(value);
              rotate();
            }}
          />
        </Field>
      ) : null}

      <Field label="Loan product">
        {products.isLoading ? (
          <LoadingState label="Loading loan products…" />
        ) : products.isError ? (
          <ErrorState title="Couldn't load loan products" onRetry={() => void products.refetch()} />
        ) : (
          <ChipSelect
            accessibilityLabel="Loan product"
            options={(products.data ?? []).map((item) => ({
              value: item.id,
              label: item.name,
              description: `${displayFormatted(item.min_amount_formatted)} – ${displayFormatted(item.max_amount_formatted)} · ${item.term_period_count} ${item.repayment_frequency === 'weekly' ? 'weeks' : 'months'}`,
            }))}
            value={productId}
            onChange={(value) => {
              setProductId(value);
              rotate();
            }}
          />
        )}
      </Field>

      <AmountInput
        label="Amount to borrow"
        value={amount}
        onChangeText={(text) => {
          setAmount(text);
          rotate();
        }}
        error={error}
        hint={product ? `Between ${displayFormatted(product.min_amount_formatted)} and ${displayFormatted(product.max_amount_formatted)}.` : undefined}
      />

      {checking ? (
        <LoadingState label="Checking eligibility…" />
      ) : eligible !== null ? (
        <Notice
          tone={eligible ? 'success' : 'warning'}
          title={eligible ? 'Eligible for this amount' : 'Not eligible for this amount'}
          message={reasons.length ? reasons.join('\n') : eligible ? 'You can submit this application.' : 'Try a smaller amount.'}
        />
      ) : null}

      {withGuarantor ? (
        <Card style={styles.section}>
          <View>
            <ThemedText type="heading">Guarantor</ThemedText>
            <ThemedText type="caption" style={{ color: theme.textMuted }}>
              Optional — someone who vouches for the customer.
            </ThemedText>
          </View>
          <Input label="Guarantor name" value={guarantorName} onChangeText={setGuarantorName} autoCapitalize="words" />
          <Input label="Guarantor phone" value={guarantorPhone} onChangeText={setGuarantorPhone} keyboardType="phone-pad" />
        </Card>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: { gap: Spacing.three },
});
