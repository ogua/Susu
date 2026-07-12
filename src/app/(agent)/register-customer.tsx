import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { drainOutbox } from '@/sync/engine';
import { enqueueCustomerRegistration } from '@/sync/ops';

export default function RegisterCustomerScreen() {
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [phone, setPhone] = useState('');
  const [nextOfKinName, setNextOfKinName] = useState('');
  const [nextOfKinPhone, setNextOfKinPhone] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    if (!firstName.trim() || !lastName.trim() || !phone.trim()) {
      setError('First name, last name, and phone are required.');
      return;
    }

    setSubmitting(true);
    try {
      await enqueueCustomerRegistration({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        phone: phone.trim(),
        next_of_kin_name: nextOfKinName.trim() || undefined,
        next_of_kin_phone: nextOfKinPhone.trim() || undefined,
      });

      setSavedMessage('Customer saved. It will sync automatically.');
      void drainOutbox();

      setTimeout(() => router.back(), 900);
    } catch {
      setError('Could not save the customer locally. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ThemedText type="small">First name</ThemedText>
      <TextInput style={styles.input} value={firstName} onChangeText={setFirstName} autoFocus />

      <ThemedText type="small">Last name</ThemedText>
      <TextInput style={styles.input} value={lastName} onChangeText={setLastName} />

      <ThemedText type="small">Phone</ThemedText>
      <TextInput style={styles.input} value={phone} onChangeText={setPhone} keyboardType="phone-pad" />

      <ThemedText type="subtitle" style={styles.sectionTitle}>Next of kin (optional)</ThemedText>

      <ThemedText type="small">Name</ThemedText>
      <TextInput style={styles.input} value={nextOfKinName} onChangeText={setNextOfKinName} />

      <ThemedText type="small">Phone</ThemedText>
      <TextInput style={styles.input} value={nextOfKinPhone} onChangeText={setNextOfKinPhone} keyboardType="phone-pad" />

      {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={styles.success}>{savedMessage}</ThemedText> : null}

      <Pressable style={[styles.button, submitting && styles.buttonDisabled]} onPress={handleSubmit} disabled={submitting}>
        {submitting ? <ActivityIndicator color="#fff" /> : <ThemedText style={styles.buttonText}>Save Customer</ThemedText>}
      </Pressable>

      <ThemedText type="small" style={styles.hint}>
        Works offline — the customer is saved on your device and synced automatically.
      </ThemedText>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 8 },
  sectionTitle: { marginTop: 12, marginBottom: 4, fontSize: 18 },
  input: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    marginBottom: 6,
  },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 12,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '700', fontSize: 16 },
  error: { color: '#d11a2a' },
  success: { color: '#1a8a3d' },
  hint: { textAlign: 'center', opacity: 0.6, marginTop: 8 },
});
