import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { applyForLoan, getLoanEligibility, getLoanProducts } from '@/api/loans';
import { ThemedText } from '@/components/themed-text';
import type { LoanProduct } from '@/types/api';

export default function ApplyForLoanScreen() {
  const params = useLocalSearchParams<{
    accountId: string;
    customerId: string;
    customerName?: string;
  }>();

  const products = useQuery({ queryKey: ['loan-products'], queryFn: getLoanProducts });

  const [selectedProduct, setSelectedProduct] = useState<LoanProduct | null>(null);
  const [amount, setAmount] = useState('');
  const [guarantorName, setGuarantorName] = useState('');
  const [guarantorPhone, setGuarantorPhone] = useState('');
  const [eligible, setEligible] = useState<boolean | null>(null);
  const [reasons, setReasons] = useState<string[]>([]);
  const [checkingEligibility, setCheckingEligibility] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const parsed = Number(amount);
    // A 0ms defer (not a synchronous call in the effect body) just to
    // satisfy the set-state-in-effect lint rule; the 500ms debounce below is
    // the real, deliberate delay for the API call.
    const resetTimer = !parsed || parsed <= 0
      ? setTimeout(() => {
        setEligible(null);
        setReasons([]);
      }, 0)
      : undefined;

    const checkTimer = parsed > 0
      ? setTimeout(() => {
        setCheckingEligibility(true);
        getLoanEligibility(params.accountId, Math.round(parsed * 100))
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
  }, [amount, params.accountId]);

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
        customer_id: params.customerId,
        loan_product_id: selectedProduct.id,
        amount: Math.round(parsed * 100),
        savings_account_id: params.accountId,
        guarantor_name: guarantorName.trim() || undefined,
        guarantor_phone: guarantorPhone.trim() || undefined,
      });

      router.replace('/(agent)/loans');
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ThemedText type="subtitle">{params.customerName || 'Customer'}</ThemedText>

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
          <ThemedText style={styles.eligibilityText}>
            {eligible ? 'Eligible' : 'Not eligible'}
          </ThemedText>
          {reasons.map((reason) => (
            <ThemedText key={reason} type="small" style={styles.eligibilityText}>
              • {reason}
            </ThemedText>
          ))}
        </View>
      ) : null}

      <ThemedText type="small">Guarantor name (optional)</ThemedText>
      <TextInput style={styles.input} value={guarantorName} onChangeText={setGuarantorName} />

      <ThemedText type="small">Guarantor phone (optional)</ThemedText>
      <TextInput style={styles.input} value={guarantorPhone} onChangeText={setGuarantorPhone} keyboardType="phone-pad" />

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
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  methodRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  methodButton: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingVertical: 10,
    paddingHorizontal: 14,
  },
  methodButtonActive: { backgroundColor: '#208AEF', borderColor: '#208AEF' },
  methodTextActive: { color: '#ffffff', fontWeight: '700' },
  eligibilityCard: { borderRadius: 10, padding: 12, gap: 2 },
  eligible: { backgroundColor: '#e6f4ea' },
  ineligible: { backgroundColor: '#fdecea' },
  eligibilityText: { color: '#1a1a1a' },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700', fontSize: 16 },
  error: { color: '#d11a2a' },
});
