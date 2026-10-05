import { StyleSheet } from 'react-native';

import { Card, Field, Input, SegmentedControl } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import type { MobileMoneyProvider } from '@/types/api';
import { MOMO_PROVIDERS } from '@/utils/momo';

/** Phone + network picker shared by every mobile-money payment form. */
export function MomoFields({
  phone,
  onPhoneChange,
  provider,
  onProviderChange,
  phoneError,
  phoneLabel = 'Mobile money number',
}: {
  phone: string;
  onPhoneChange: (value: string) => void;
  provider: MobileMoneyProvider;
  onProviderChange: (value: MobileMoneyProvider) => void;
  phoneError?: string | null;
  phoneLabel?: string;
}) {
  return (
    <Card tone="muted" style={styles.card}>
      <Input
        label={phoneLabel}
        icon="phone"
        keyboardType="phone-pad"
        autoComplete="tel"
        value={phone}
        onChangeText={onPhoneChange}
        placeholder="024 123 4567"
        error={phoneError}
      />
      <Field label="Network">
        <SegmentedControl accessibilityLabel="Mobile money network" options={MOMO_PROVIDERS} value={provider} onChange={onProviderChange} />
      </Field>
    </Card>
  );
}

const styles = StyleSheet.create({
  card: { gap: Spacing.three },
});
