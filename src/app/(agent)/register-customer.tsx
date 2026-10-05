import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import {
  Button,
  Card,
  ChipSelect,
  Field,
  Input,
  KeyValueRow,
  Notice,
  ResultView,
  Screen,
  SegmentedControl,
  type Option,
} from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { drainOutbox } from '@/sync/engine';
import { enqueueCustomerRegistration } from '@/sync/ops';
import type { ClientType } from '@/types/api';
import { isValidIsoDate, maskDateInput } from '@/utils/format';

const CLIENT_TYPES: Option<ClientType>[] = [
  { value: 'individual', label: 'Individual', icon: 'person' },
  { value: 'business', label: 'Business', icon: 'store' },
];

const GENDERS: Option<string>[] = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
];

const ID_TYPES: Option<string>[] = [
  { value: 'ghana_card', label: 'Ghana Card' },
  { value: 'voters_id', label: "Voter's ID" },
  { value: 'passport', label: 'Passport' },
  { value: 'drivers_license', label: "Driver's License" },
  { value: 'other', label: 'Other' },
];

const BUSINESS_STRUCTURES: Option<string>[] = [
  { value: 'sole_proprietorship', label: 'Sole Proprietorship' },
  { value: 'partnership', label: 'Partnership' },
  { value: 'limited_liability_company', label: 'Limited Liability Company' },
  { value: 'ngo_cbo', label: 'NGO/CBO' },
  { value: 'cooperative', label: 'Cooperative' },
  { value: 'other', label: 'Other' },
];

type Errors = Partial<Record<'firstName' | 'lastName' | 'phone' | 'dateOfBirth' | 'businessName' | 'businessStructure' | 'businessStartDate', string>>;

export default function RegisterCustomerScreen() {
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
  const [errors, setErrors] = useState<Errors>({});
  const [saveError, setSaveError] = useState<string | null>(null);
  const [savedCustomerId, setSavedCustomerId] = useState<string | null>(null);
  const submittingRef = useRef(false);

  const fullName = `${firstName.trim()} ${lastName.trim()}`.trim();

  function validate(): Errors {
    const next: Errors = {};
    if (!firstName.trim()) next.firstName = 'Enter the first name.';
    if (!lastName.trim()) next.lastName = 'Enter the last name.';
    if (!phone.trim()) next.phone = 'Enter a phone number.';
    if (dateOfBirth.trim() && !isValidIsoDate(dateOfBirth.trim())) next.dateOfBirth = 'Use a real date, e.g. 1990-04-21.';
    if (clientType === 'business') {
      if (!businessName.trim()) next.businessName = 'Enter the business name.';
      if (!businessStructure) next.businessStructure = 'Choose the business structure.';
      if (!isValidIsoDate(businessStartDate.trim())) next.businessStartDate = 'Use a real date, e.g. 2019-06-01.';
    }

    return next;
  }

  async function handleSubmit() {
    if (submittingRef.current || savedCustomerId) {
      return;
    }
    setSaveError(null);
    const found = validate();
    setErrors(found);
    if (Object.keys(found).length > 0) {
      return;
    }

    submittingRef.current = true;
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

      setSavedCustomerId(customerId);
      void drainOutbox();
    } catch {
      setSaveError("The customer couldn't be saved on the phone. Nothing was recorded — please try again.");
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }

  if (savedCustomerId) {
    return (
      <Screen
        footer={
          <>
            <Button
              title="Open a savings account"
              icon="wallet"
              size="lg"
              onPress={() =>
                router.replace({
                  pathname: '/(agent)/open-account',
                  params: { customerId: savedCustomerId, customerName: clientType === 'business' ? businessName.trim() : fullName },
                })
              }
            />
            <Button title="Done" variant="ghost" onPress={() => router.back()} />
          </>
        }
      >
        <ResultView
          tone="pending"
          title="Customer saved"
          message="Saved on this phone and synced automatically when you're online. You can open their savings account now, even offline."
        >
          <Card>
            <KeyValueRow label="Name" value={clientType === 'business' ? `${businessName.trim()} (${fullName})` : fullName} />
            <KeyValueRow label="Phone" value={phone.trim()} />
            <KeyValueRow label="Client type" value={clientType === 'business' ? 'Business' : 'Individual'} />
          </Card>
        </ResultView>
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <Button title="Save customer" icon="personAdd" size="lg" loading={submitting} loadingTitle="Saving…" onPress={handleSubmit} />
      }
    >
      <Notice tone="info" message="Works offline. The customer is saved on this phone and synced automatically." />

      <Field label="Client type">
        <SegmentedControl accessibilityLabel="Client type" options={CLIENT_TYPES} value={clientType} onChange={setClientType} />
      </Field>

      <Card style={styles.section}>
        <ThemedText type="heading">{clientType === 'business' ? 'Owner / contact person' : 'Personal details'}</ThemedText>
        <Input
          label="First name"
          value={firstName}
          onChangeText={setFirstName}
          autoCapitalize="words"
          autoComplete="given-name"
          error={errors.firstName}
        />
        <Input
          label="Last name"
          value={lastName}
          onChangeText={setLastName}
          autoCapitalize="words"
          autoComplete="family-name"
          error={errors.lastName}
        />
        <Input
          label="Phone number"
          icon="phone"
          value={phone}
          onChangeText={setPhone}
          keyboardType="phone-pad"
          placeholder="024 123 4567"
          error={errors.phone}
        />
        <Field label="Gender" optional>
          <SegmentedControl accessibilityLabel="Gender" options={GENDERS} value={gender} onChange={setGender} />
        </Field>
        <Input
          label="Date of birth"
          optional
          icon="calendar"
          value={dateOfBirth}
          onChangeText={(text) => setDateOfBirth(maskDateInput(text))}
          placeholder="YYYY-MM-DD"
          keyboardType="number-pad"
          maxLength={10}
          error={errors.dateOfBirth}
        />
        <Field label="ID type" optional>
          <ChipSelect accessibilityLabel="ID type" options={ID_TYPES} value={idType} onChange={setIdType} />
        </Field>
        {idType ? <Input label="ID number" value={idNumber} onChangeText={setIdNumber} autoCapitalize="characters" /> : null}
        <Input label="Address" optional value={address} onChangeText={setAddress} />
      </Card>

      {clientType === 'business' ? (
        <Card style={styles.section}>
          <ThemedText type="heading">Business details</ThemedText>
          <Input label="Business name" icon="store" value={businessName} onChangeText={setBusinessName} error={errors.businessName} />
          <Field label="Business structure">
            <ChipSelect
              accessibilityLabel="Business structure"
              options={BUSINESS_STRUCTURES}
              value={businessStructure}
              onChange={setBusinessStructure}
            />
            {errors.businessStructure ? (
              <ThemedText type="small" themeColor="danger">
                {errors.businessStructure}
              </ThemedText>
            ) : null}
          </Field>
          <Input
            label="Business start date"
            icon="calendar"
            value={businessStartDate}
            onChangeText={(text) => setBusinessStartDate(maskDateInput(text))}
            placeholder="YYYY-MM-DD"
            keyboardType="number-pad"
            maxLength={10}
            error={errors.businessStartDate}
          />
        </Card>
      ) : null}

      <Card style={styles.section}>
        <ThemedText type="heading">Next of kin</ThemedText>
        <ThemedText type="caption" themeColor="textMuted">
          Optional, but helps your branch reach the family if needed.
        </ThemedText>
        <Input label="Name" value={nextOfKinName} onChangeText={setNextOfKinName} autoCapitalize="words" />
        <Input label="Phone number" value={nextOfKinPhone} onChangeText={setNextOfKinPhone} keyboardType="phone-pad" />
        <Input label="Relationship" value={nextOfKinRelationship} onChangeText={setNextOfKinRelationship} placeholder="e.g. Sister" />
      </Card>

      {Object.keys(errors).length > 0 ? <Notice tone="danger" message="Please fix the highlighted fields above." /> : null}
      {saveError ? <Notice tone="danger" message={saveError} /> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: { gap: Spacing.three },
});
