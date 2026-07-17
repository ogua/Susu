import { router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen } from '@/components/ui';
import { Palette } from '@/constants/theme';
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
    <Screen>
      <Card style={styles.form}>
        <ThemedText type="subtitle">Customer</ThemedText>
        <Input label="First name" value={firstName} onChangeText={setFirstName} autoFocus />
        <Input label="Last name" value={lastName} onChangeText={setLastName} />
        <Input label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
      </Card>

      <Card style={styles.form}>
        <ThemedText type="subtitle">Next of kin (optional)</ThemedText>
        <Input label="Name" value={nextOfKinName} onChangeText={setNextOfKinName} />
        <Input
          label="Phone"
          value={nextOfKinPhone}
          onChangeText={setNextOfKinPhone}
          keyboardType="phone-pad"
        />
      </Card>

      {error ? <ThemedText style={{ color: Palette.danger }}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={{ color: Palette.success }}>{savedMessage}</ThemedText> : null}

      <Button title="Save Customer" loading={submitting} onPress={handleSubmit} />

      <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
        Works offline — the customer is saved on your device and synced automatically.
      </ThemedText>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 10 },
  hint: { textAlign: 'center' },
});
