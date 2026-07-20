import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { apiErrorMessage } from '@/api/client';
import { getLoanProducts } from '@/api/loans';
import { getLoanGroups } from '@/api/loanGroups';
import { applyForGroupLoan } from '@/api/groupLoans';
import { ThemedText } from '@/components/themed-text';
import type { LoanGroup, LoanProduct } from '@/types/api';
import { Palette } from '@/constants/theme';

export default function ApplyForGroupLoanScreen() {
  const loanGroups = useQuery({ queryKey: ['loan-groups'], queryFn: () => getLoanGroups() });
  const products = useQuery({ queryKey: ['loan-products'], queryFn: getLoanProducts });

  const [selectedGroup, setSelectedGroup] = useState<LoanGroup | null>(null);
  const [selectedProduct, setSelectedProduct] = useState<LoanProduct | null>(null);
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    const parsed = Number(amount);
    if (!parsed || parsed <= 0) {
      setError('Enter a valid amount.');
      return;
    }
    if (!selectedGroup) {
      setError('Select a loan group.');
      return;
    }
    if (!selectedProduct) {
      setError('Select a loan product.');
      return;
    }

    setSubmitting(true);
    try {
      await applyForGroupLoan({
        loan_group_id: selectedGroup.id,
        loan_product_id: selectedProduct.id,
        amount: Math.round(parsed * 100),
        notes: notes.trim() || undefined,
      });

      router.replace('/(agent)/group-loans/index');
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ThemedText type="small">Loan group</ThemedText>
      {loanGroups.isLoading ? (
        <ActivityIndicator />
      ) : (
        <View style={styles.methodRow}>
          {(loanGroups.data?.data ?? []).map((group) => (
            <Pressable
              key={group.id}
              style={[styles.methodButton, selectedGroup?.id === group.id && styles.methodButtonActive]}
              onPress={() => setSelectedGroup(group)}
            >
              <ThemedText style={selectedGroup?.id === group.id ? styles.methodTextActive : undefined}>
                {group.name}
              </ThemedText>
            </Pressable>
          ))}
        </View>
      )}

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

      <ThemedText type="small">Notes (optional)</ThemedText>
      <TextInput style={styles.input} value={notes} onChangeText={setNotes} multiline />

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
