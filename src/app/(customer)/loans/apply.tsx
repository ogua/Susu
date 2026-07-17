import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { getCustomerAccounts } from '@/api/accounts';
import { apiErrorMessage } from '@/api/client';
import { applyForLoan, getLoanEligibility, getLoanProducts } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import type { LoanProduct, SavingsAccount } from '@/types/api';
import { Palette } from '@/constants/theme';

export default function CustomerApplyForLoanScreen() {
  const products = useQuery({ queryKey: ['loan-products'], queryFn: getLoanProducts });
  const accounts = useQuery({ queryKey: ['customer', 'accounts'], queryFn: getCustomerAccounts });

  const [selectedAccountId, setSelectedAccountId] = useState<string | null>(null);
  const [selectedProduct, setSelectedProduct] = useState<LoanProduct | null>(null);
  const [amount, setAmount] = useState('');
  const [eligible, setEligible] = useState<boolean | null>(null);
  const [reasons, setReasons] = useState<string[]>([]);
  const [checkingEligibility, setCheckingEligibility] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Derived rather than synced via an effect: defaults to the first account
  // until the user explicitly picks a different one.
  const selectedAccount: SavingsAccount | null =
    accounts.data?.find((account) => account.id === selectedAccountId) ?? accounts.data?.[0] ?? null;

  useEffect(() => {
    const parsed = Number(amount);
    const resetTimer = !parsed || parsed <= 0 || !selectedAccount
      ? setTimeout(() => {
        setEligible(null);
        setReasons([]);
      }, 0)
      : undefined;

    const checkTimer = parsed > 0 && selectedAccount
      ? setTimeout(() => {
        setCheckingEligibility(true);
        getLoanEligibility(selectedAccount.id, Math.round(parsed * 100))
          .then((result) => {
            setEligible(result.eligible);
            setReasons(result.reasons);
          })
          .catch(() => {
            setEligible(null);
            setReasons([]);
          })
          .finally(() => setCheckingEligibility(false));
      }, 500)
      : undefined;

    return () => {
      clearTimeout(resetTimer);
      clearTimeout(checkTimer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [amount, selectedAccount?.id]);

  async function handleSubmit() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }
    if (!selectedProduct) {
      setError('Select a loan product.');
      return;
    }

    setSubmitting(true);
    try {
      await applyForLoan({
        loan_product_id: selectedProduct.id,
        amount: Math.round(parsed * 100),
        savings_account_id: selectedAccount?.id,
      });

      router.replace('/(customer)/loans');
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      {accounts.data && accounts.data.length > 1 ? (
        <>
          <ThemedText type="small">Susu account</ThemedText>
          <View style={styles.methodRow}>
            {accounts.data.map((account) => (
              <Pressable
                key={account.id}
                style={[styles.methodButton, selectedAccount?.id === account.id && styles.methodButtonActive]}
                onPress={() => setSelectedAccountId(account.id)}
              >
                <ThemedText style={selectedAccount?.id === account.id ? styles.methodTextActive : undefined}>
                  {account.account_number}
                </ThemedText>
              </Pressable>
            ))}
          </View>
        </>
      ) : null}

      <ThemedText type="small">Loan product</ThemedText>
      {products.isLoading ? (
        <ActivityIndicator />
      ) : (
        <View style={styles.methodRow}>
          {(products.data ?? []).map((product) => (
            <Pressable
              key={product.id}
              style={[styles.methodButton, selectedProduct?.id === product.id && styles.methodButtonActive]}
              onPress={() => setSelectedProduct(product)}
            >
              <ThemedText style={selectedProduct?.id === product.id ? styles.methodTextActive : undefined}>
                {product.name}
              </ThemedText>
            </Pressable>
          ))}
        </View>
      )}

      <ThemedText type="small">Amount (GHS)</ThemedText>
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={amount}
        onChangeText={setAmount}
        placeholder="0.00"
      />

      {checkingEligibility ? (
        <ActivityIndicator />
      ) : eligible !== null ? (
        <View style={[styles.eligibilityCard, eligible ? styles.eligible : styles.ineligible]}>
          <ThemedText style={styles.eligibilityText}>{eligible ? 'Eligible' : 'Not eligible'}</ThemedText>
          {reasons.map((reason) => (
            <ThemedText key={reason} type="small" style={styles.eligibilityText}>
              • {reason}
            </ThemedText>
          ))}
        </View>
      ) : null}

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Submit Application</ThemedText>}
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 10 },
  input: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  methodRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  methodButton: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingVertical: 10,
    paddingHorizontal: 14,
  },
  methodButtonActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  methodTextActive: { color: '#ffffff', fontWeight: '700' },
  eligibilityCard: { borderRadius: 10, padding: 12, gap: 2 },
  eligible: { backgroundColor: '#e6f4ea' },
  ineligible: { backgroundColor: '#fdecea' },
  eligibilityText: { color: '#1a1a1a' },
  button: {
    backgroundColor: Palette.primary500,
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700', fontSize: 16 },
  error: { color: Palette.danger },
});
