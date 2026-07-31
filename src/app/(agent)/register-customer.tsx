import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Button, Card, Input, Screen } from '@/components/ui';
import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueCustomerRegistration } from '@/sync/ops';
import type { ClientType } from '@/types/api';

const CLIENT_TYPES: { value: ClientType; label: string }[] = [
  { value: 'individual', label: 'Individual' },
  { value: 'business', label: 'Business' },
];

const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
];

const ID_TYPES = [
  { value: 'ghana_card', label: 'Ghana Card' },
  { value: 'voters_id', label: "Voter's ID" },
  { value: 'passport', label: 'Passport' },
  { value: 'drivers_license', label: "Driver's License" },
  { value: 'other', label: 'Other' },
];

const BUSINESS_STRUCTURES = [
  { value: 'sole_proprietorship', label: 'Sole Proprietorship' },
  { value: 'partnership', label: 'Partnership' },
  { value: 'limited_liability_company', label: 'Limited Liability Company' },
  { value: 'ngo_cbo', label: 'NGO/CBO' },
  { value: 'cooperative', label: 'Cooperative' },
  { value: 'other', label: 'Other' },
];

function isValidDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(value);
  return !Number.isNaN(parsed.getTime());
}

export default function RegisterCustomerScreen() {
  const theme = useTheme();

  const [clientType, setClientType] = useState<ClientType>('individual');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [phone, setPhone] = useState('');
  const [gender, setGender] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const [idType, setIdType] = useState('');
  const [idNumber, setIdNumber] = useState('');
  const [address, setAddress] = useState('');
  const [businessName, setBusinessName] = useState('');
  const [businessStructure, setBusinessStructure] = useState('');
  const [businessStartDate, setBusinessStartDate] = useState('');
  const [nextOfKinName, setNextOfKinName] = useState('');
  const [nextOfKinPhone, setNextOfKinPhone] = useState('');
  const [nextOfKinRelationship, setNextOfKinRelationship] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);
  const [savedCustomerId, setSavedCustomerId] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);

    if (!firstName.trim() || !lastName.trim() || !phone.trim()) {
      setError('First name, last name, and phone are required.');
      return;
    }
    if (dateOfBirth.trim() && !isValidDate(dateOfBirth.trim())) {
      setError('Date of birth must be a valid date (YYYY-MM-DD).');
      return;
    }
    if (clientType === 'business') {
      if (!businessName.trim() || !businessStructure || !businessStartDate.trim()) {
        setError('Business name, structure, and start date are required for a business client.');
        return;
      }
      if (!isValidDate(businessStartDate.trim())) {
        setError('Business start date must be a valid date (YYYY-MM-DD).');
        return;
      }
    }

    setSubmitting(true);
    try {
      // The returned id is also the customer's actual server-side primary
      // key once synced (client_reference-as-id, see CreateCustomerAction) —
      // usable immediately to open a savings account for them, offline.
      const customerId = await enqueueCustomerRegistration({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        phone: phone.trim(),
        client_type: clientType,
        gender: gender || undefined,
        date_of_birth: dateOfBirth.trim() || undefined,
        id_type: idType || undefined,
        id_number: idNumber.trim() || undefined,
        address: address.trim() || undefined,
        next_of_kin_name: nextOfKinName.trim() || undefined,
        next_of_kin_phone: nextOfKinPhone.trim() || undefined,
        next_of_kin_relationship: nextOfKinRelationship.trim() || undefined,
        ...(clientType === 'business'
          ? {
              business_name: businessName.trim(),
              business_structure: businessStructure,
              business_start_date: businessStartDate.trim(),
            }
          : {}),
      });

      setSavedMessage('Customer saved. It will sync automatically.');
      setSavedCustomerId(customerId);
      void drainOutbox();
    } catch {
      setError('Could not save the customer locally. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  function segment(options: { value: string; label: string }[], selected: string, onSelect: (value: string) => void) {
    return (
      <View style={styles.segmentRow}>
        {options.map((option) => {
          const active = selected === option.value;

          return (
            <Pressable
              key={option.value}
              style={[
                styles.segment,
                { borderColor: active ? Palette.primary500 : theme.border },
                active && styles.segmentActive,
              ]}
              onPress={() => onSelect(option.value)}
            >
              <ThemedText type="smallBold" style={active ? styles.segmentTextActive : undefined}>
                {option.label}
              </ThemedText>
            </Pressable>
          );
        })}
      </View>
    );
  }

  function segmentWrap(options: { value: string; label: string }[], selected: string, onSelect: (value: string) => void) {
    return (
      <View style={styles.segmentWrapRow}>
        {options.map((option) => {
          const active = selected === option.value;

          return (
            <Pressable
              key={option.value}
              style={[
                styles.segmentWrapChip,
                { borderColor: active ? Palette.primary500 : theme.border },
                active && styles.segmentActive,
              ]}
              onPress={() => onSelect(option.value)}
            >
              <ThemedText type="smallBold" style={active ? styles.segmentTextActive : undefined}>
                {option.label}
              </ThemedText>
            </Pressable>
          );
        })}
      </View>
    );
  }

  return (
    <Screen>
      <Card style={styles.form}>
        <ThemedText type="subtitle">Client type</ThemedText>
        {segment(CLIENT_TYPES, clientType, (value) => setClientType(value as ClientType))}
      </Card>

      <Card style={styles.form}>
        <ThemedText type="subtitle">Customer</ThemedText>
        <Input label="First name" value={firstName} onChangeText={setFirstName} autoFocus />
        <Input label="Last name" value={lastName} onChangeText={setLastName} />
        <Input label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
        <ThemedText type="small" themeColor="textSecondary">
          Gender
        </ThemedText>
        {segment(GENDERS, gender, setGender)}
        <Input
          label="Date of birth (YYYY-MM-DD)"
          value={dateOfBirth}
          onChangeText={setDateOfBirth}
          placeholder="YYYY-MM-DD"
          keyboardType="numbers-and-punctuation"
        />
        <ThemedText type="small" themeColor="textSecondary">
          ID type
        </ThemedText>
        {segmentWrap(ID_TYPES, idType, setIdType)}
        <Input label="ID number" value={idNumber} onChangeText={setIdNumber} />
        <Input label="Address" value={address} onChangeText={setAddress} />
      </Card>

      {clientType === 'business' ? (
        <Card style={styles.form}>
          <ThemedText type="subtitle">Business</ThemedText>
          <Input label="Business name" value={businessName} onChangeText={setBusinessName} />
          <ThemedText type="small" themeColor="textSecondary">
            Business structure
          </ThemedText>
          {segmentWrap(BUSINESS_STRUCTURES, businessStructure, setBusinessStructure)}
          <Input
            label="Business start date (YYYY-MM-DD)"
            value={businessStartDate}
            onChangeText={setBusinessStartDate}
            placeholder="YYYY-MM-DD"
            keyboardType="numbers-and-punctuation"
          />
        </Card>
      ) : null}

      <Card style={styles.form}>
        <ThemedText type="subtitle">Next of kin (optional)</ThemedText>
        <Input label="Name" value={nextOfKinName} onChangeText={setNextOfKinName} />
        <Input
          label="Phone"
          value={nextOfKinPhone}
          onChangeText={setNextOfKinPhone}
          keyboardType="phone-pad"
        />
        <Input label="Relationship" value={nextOfKinRelationship} onChangeText={setNextOfKinRelationship} />
      </Card>

      {error ? <ThemedText style={{ color: Palette.danger }}>{error}</ThemedText> : null}
      {savedMessage ? <ThemedText style={{ color: Palette.success }}>{savedMessage}</ThemedText> : null}

      {savedCustomerId ? (
        <>
          <Button
            title="Open Savings Account"
            onPress={() =>
              router.push({
                pathname: '/(agent)/open-account',
                params: { customerId: savedCustomerId, customerName: `${firstName.trim()} ${lastName.trim()}` },
              })
            }
          />
          <Button title="Done" variant="secondary" onPress={() => router.back()} />
        </>
      ) : (
        <Button title="Save Customer" loading={submitting} onPress={handleSubmit} />
      )}

      <ThemedText type="small" themeColor="textSecondary" style={styles.hint}>
        Works offline — the customer is saved on your device and synced automatically.
      </ThemedText>
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: { gap: 10 },
  hint: { textAlign: 'center' },
  segmentRow: { flexDirection: 'row', gap: 8 },
  segment: {
    flex: 1,
    borderWidth: 1,
    borderRadius: Radii.sm,
    paddingVertical: 10,
    alignItems: 'center',
  },
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
