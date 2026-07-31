import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { getSavingsProducts } from '@/api/products';
import { ThemedText } from '@/components/themed-text';
import { Button, Card, EmptyState, Input, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueOpenAccount } from '@/sync/ops';
import type { SavingsProduct } from '@/types/api';

function isValidFutureDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return false;
  return parsed.getTime() > Date.now();
}

/** Mirrors App\Http\Requests\Api\V1\StoreSavingsAccountRequest::payloadRules(). */
export default function OpenAccountScreen() {
  const theme = useTheme();
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
  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);

  const activeProducts = (products.data ?? []).filter((product) => product.is_active);
  const selectedProduct = activeProducts.find((product) => product.id === selectedProductId) ?? null;

  async function handleSubmit() {
    setError(null);
    if (!selectedProduct) {
      setError('Select a product first.');
      return;
    }

    let parsedContribution: number | undefined;
    if (contributionAmount.trim()) {
      const parsed = Number(contributionAmount);
      if (!parsed || parsed <= 0) {
        setError('Enter a valid amount.');
        return;
      }
      parsedContribution = Math.round(parsed * 100);
    }

    let parsedTarget: number | undefined;
    if (selectedProduct.type === 'target') {
      const parsed = Number(targetAmount);
      if (!parsed || parsed <= 0) {
        setError('Target accounts require a target amount.');
        return;
      }
      parsedTarget = Math.round(parsed * 100);
    }

    if (selectedProduct.type === 'target' || selectedProduct.type === 'fixed_deposit') {
      if (!isValidFutureDate(maturesAt.trim())) {
        setError('Enter a valid future maturity date (YYYY-MM-DD).');
        return;
      }
    }

    setSubmitting(true);
    try {
      await enqueueOpenAccount({
        customer_id: customerId,
        savings_product_id: selectedProduct.id,
        contribution_amount: parsedContribution,
        target_amount: parsedTarget,
        matures_at:
          selectedProduct.type === 'target' || selectedProduct.type === 'fixed_deposit'
            ? maturesAt.trim()
            : undefined,
      });

      setSavedMessage('Account saved. It will sync automatically.');
      void drainOutbox();

      setTimeout(() => router.back(), 900);
    } catch {
      setError('Could not save the account locally. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  function productLabel(product: SavingsProduct): string {
    return product.name;
  }

  function contributionLabel(): string {
    return selectedProduct?.type === 'fixed_deposit' ? 'Principal amount (GHS)' : 'Daily contribution (GHS)';
  }

  return (
    <Screen>
      <Card style={styles.form}>
        <ThemedText type="subtitle">Customer</ThemedText>
        <ThemedText>{customerName || customerId}</ThemedText>
      </Card>

      <Card style={styles.form}>
        <ThemedText type="subtitle">Product</ThemedText>
        {products.isLoading ? (
          <ActivityIndicator />
        ) : products.isError ? (
          <EmptyState title="Could not load products" hint="Check your connection and try again." />
        ) : activeProducts.length === 0 ? (
          <EmptyState title="No active products" />
        ) : (
          <View style={styles.segmentWrapRow}>
            {activeProducts.map((product) => {
              const active = selectedProductId === product.id;

              return (
                <Pressable
                  key={product.id}
                  style={[
                    styles.segmentWrapChip,
                    { borderColor: active ? Palette.primary500 : theme.border },
                    active && styles.segmentActive,
                  ]}
                  onPress={() => setSelectedProductId(product.id)}
                >
                  <ThemedText type="smallBold" style={active ? styles.segmentTextActive : undefined}>
                    {productLabel(product)}
                  </ThemedText>
                </Pressable>
              );
            })}
          </View>
        )}
      </Card>

      {selectedProduct && selectedProduct.type !== 'shares' ? (
        <Card style={styles.form}>
          <Input
            label={contributionLabel()}
            keyboardType="decimal-pad"
            value={contributionAmount}
            onChangeText={setContributionAmount}
            placeholder="5.00"
          />

          {selectedProduct.type === 'target' ? (
            <Input
              label="Target amount (GHS)"
              keyboardType="decimal-pad"
              value={targetAmount}
              onChangeText={setTargetAmount}
              placeholder="5000.00"
            />
          ) : null}

          {selectedProduct.type === 'target' || selectedProduct.type === 'fixed_deposit' ? (
            <Input
              label="Maturity date (YYYY-MM-DD)"
              value={maturesAt}
              onChangeText={setMaturesAt}
              placeholder="YYYY-MM-DD"
              keyboardType="numbers-and-punctuation"
            />
          ) : null}
        </Card>
      ) : null}

      {error ? <ThemedText style={{ color: Palette.danger }}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={{ color: Palette.success }}>{savedMessage}</ThemedText> : null}

      <Button title="Open Account" loading={submitting} onPress={handleSubmit} />

      <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
        Works offline — the account is saved on your device and synced automatically.
      </ThemedText>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 10 },
  hint: { textAlign: 'center' },
  segmentWrapRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  segmentWrapChip: {
    borderWidth: 1,
    borderRadius: Radii.sm,
    paddingVertical: 8,
    paddingHorizontal: 14,
  },
  segmentActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  segmentTextActive: { color: '#ffffff' },
});
