import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { getSavingsProducts } from '@/api/products';
import { ThemedText } from '@/components/themed-text';
import {
  AmountInput,
  Avatar,
  Button,
  Card,
  ChipSelect,
  ErrorState,
  Field,
  Input,
  KeyValueRow,
  LoadingState,
  Notice,
  ResultView,
  Screen,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueOpenAccount } from '@/sync/ops';
import type { SavingsProductType } from '@/types/api';
import { humanize, isValidIsoDate, maskDateInput } from '@/utils/format';
import { formatMoney, parseAmountToMinor } from '@/utils/money';

const PRODUCT_TYPE_LABELS: Record<SavingsProductType, string> = {
  daily_susu: 'Daily susu',
  target: 'Target savings',
  fixed_deposit: 'Fixed deposit',
  shares: 'Shares',
};

function isFutureIsoDate(value: string): boolean {
  return isValidIsoDate(value) && new Date(value).getTime() > Date.now();
}

/** Mirrors App\Http\Requests\Api\V1\StoreSavingsAccountRequest::payloadRules(). */
export default function OpenAccountScreen() {
  const { customerId, customerName } = useLocalSearchParams<{ customerId: string; customerName?: string }>();

  const products = useQuery({
    queryKey: ['savings-products'],
    queryFn: getSavingsProducts,
    staleTime: 10 * 60_000,
  });

  const [selectedProductId, setSelectedProductId] = useState<string | null>(null);
  const [contributionAmount, setContributionAmount] = useState('');
  const [targetAmount, setTargetAmount] = useState('');
  const [maturesAt, setMaturesAt] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<{ product?: string; contribution?: string; target?: string; maturity?: string }>({});
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const submittingRef = useRef(false);

  const activeProducts = (products.data ?? []).filter((product) => product.is_active);
  const selectedProduct = activeProducts.find((product) => product.id === selectedProductId) ?? null;
  const needsMaturity = selectedProduct?.type === 'target' || selectedProduct?.type === 'fixed_deposit';

  async function handleSubmit() {
    if (submittingRef.current || saved) {
      return;
    }
    setSaveError(null);
    const next: typeof errors = {};
    if (!selectedProduct) next.product = 'Choose a savings product.';

    let parsedContribution: number | undefined;
    if (contributionAmount.trim()) {
      const parsed = parseAmountToMinor(contributionAmount);
      if (!parsed) next.contribution = 'Enter an amount above zero, or leave it empty to use the product default.';
      else parsedContribution = parsed;
    }

    let parsedTarget: number | undefined;
    if (selectedProduct?.type === 'target') {
      const parsed = parseAmountToMinor(targetAmount);
      if (!parsed) next.target = 'Target accounts need a target amount.';
      else parsedTarget = parsed;
    }

    if (needsMaturity && !isFutureIsoDate(maturesAt.trim())) {
      next.maturity = 'Enter a future date, e.g. 2027-01-31.';
    }

    setErrors(next);
    if (Object.keys(next).length > 0 || !selectedProduct) {
      return;
    }

    submittingRef.current = true;
    setSubmitting(true);
    try {
      await enqueueOpenAccount({
        customer_id: customerId,
        savings_product_id: selectedProduct.id,
        contribution_amount: parsedContribution,
        target_amount: parsedTarget,
        matures_at: needsMaturity ? maturesAt.trim() : undefined,
      });

      setSaved(true);
      void drainOutbox();
    } catch {
      setSaveError("The account couldn't be saved on the phone. Nothing was recorded — please try again.");
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  if (saved && selectedProduct) {
    return (
      <Screen footer={<Button title="Done" size="lg" onPress={() => router.back()} />}>
        <ResultView
          tone="pending"
          title="Account saved"
          message="Saved on this phone. The account number is assigned when it syncs — it will then appear in your accounts list for collections."
        >
          <Card>
            <KeyValueRow label="Customer" value={customerName || '—'} />
            <KeyValueRow label="Product" value={selectedProduct.name} />
            {contributionAmount ? (
              <KeyValueRow label="Contribution" value={formatMoney(parseAmountToMinor(contributionAmount) ?? 0)} />
            ) : null}
          </Card>
        </ResultView>
      </Screen>
    );
  }

  return (
    <Screen
      footer={<Button title="Open account" icon="wallet" size="lg" loading={submitting} loadingTitle="Saving…" onPress={handleSubmit} />}
    >
      <Card style={styles.customer}>
        <Avatar name={customerName || '?'} size={44} />
        <View style={styles.flex}>
          <ThemedText type="caption" themeColor="textMuted">
            Opening an account for
          </ThemedText>
          <ThemedText type="heading">{customerName || 'Customer'}</ThemedText>
        </View>
      </Card>

      <Field label="Savings product">
        {products.isLoading ? (
          <LoadingState label="Loading products…" />
        ) : products.isError ? (
          <ErrorState
            title="Couldn't load products"
            hint="The product list needs a connection the first time. Try again when online."
            onRetry={() => void products.refetch()}
          />
        ) : activeProducts.length === 0 ? (
          <Notice tone="warning" message="No active savings products. Ask your branch to set one up." />
        ) : (
          <ChipSelect
            accessibilityLabel="Savings product"
            options={activeProducts.map((product) => ({
              value: product.id,
              label: product.name,
              description: PRODUCT_TYPE_LABELS[product.type] ?? humanize(product.type),
            }))}
            value={selectedProductId}
            onChange={setSelectedProductId}
          />
        )}
        {errors.product ? (
          <ThemedText type="small" themeColor="danger">
            {errors.product}
          </ThemedText>
        ) : null}
      </Field>

      {selectedProduct && selectedProduct.type !== 'shares' ? (
        <Card style={styles.section}>
          <AmountInput
            size="md"
            label={selectedProduct.type === 'fixed_deposit' ? 'Principal amount' : 'Agreed contribution'}
            value={contributionAmount}
            onChangeText={setContributionAmount}
            error={errors.contribution}
            hint={
              selectedProduct.contribution_amount > 0
                ? `Leave empty to use the product default of ${formatMoney(selectedProduct.contribution_amount)}.`
                : undefined
            }
          />

          {selectedProduct.type === 'target' ? (
            <AmountInput size="md" label="Target amount" value={targetAmount} onChangeText={setTargetAmount} error={errors.target} />
          ) : null}

          {needsMaturity ? (
            <Input
              label="Maturity date"
              icon="calendar"
              value={maturesAt}
              onChangeText={(text) => setMaturesAt(maskDateInput(text))}
              placeholder="YYYY-MM-DD"
              keyboardType="number-pad"
              maxLength={10}
              error={errors.maturity}
              hint={selectedProduct.type === 'fixed_deposit' ? 'Funds cannot be withdrawn before this date.' : undefined}
            />
          ) : null}
        </Card>
      ) : null}

      {selectedProduct?.type === 'shares' ? (
        <Notice tone="info" message="Shares accounts have no contribution amount. The customer buys shares at the product's par value." />
      ) : null}

      {saveError ? <Notice tone="danger" message={saveError} /> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  customer: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  section: { gap: Spacing.three },
});
