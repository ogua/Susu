import { SymbolView, type SymbolViewProps } from 'expo-symbols';
import type { ColorValue, StyleProp, ViewStyle } from 'react-native';

import { useTheme } from '@/hooks/use-theme';

/**
 * Semantic icon names → SF Symbols (iOS) and Material Symbols (Android/web).
 * expo-symbols bundles the Material Symbols font, so icons render offline.
 */
const ICONS = {
  home: { ios: 'house.fill', android: 'home' },
  wallet: { ios: 'wallet.pass.fill', android: 'account_balance_wallet' },
  cash: { ios: 'banknote.fill', android: 'payments' },
  bank: { ios: 'building.columns.fill', android: 'account_balance' },
  sync: { ios: 'arrow.triangle.2.circlepath', android: 'sync' },
  personAdd: { ios: 'person.badge.plus', android: 'person_add' },
  person: { ios: 'person.crop.circle.fill', android: 'account_circle' },
  receipt: { ios: 'doc.text.fill', android: 'receipt_long' },
  search: { ios: 'magnifyingglass', android: 'search' },
  offline: { ios: 'wifi.slash', android: 'wifi_off' },
  checkCircle: { ios: 'checkmark.circle.fill', android: 'check_circle' },
  error: { ios: 'xmark.octagon.fill', android: 'error' },
  warning: { ios: 'exclamationmark.triangle.fill', android: 'warning' },
  info: { ios: 'info.circle.fill', android: 'info' },
  logout: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout' },
  clock: { ios: 'clock.fill', android: 'schedule' },
  group: { ios: 'person.3.fill', android: 'groups' },
  loan: { ios: 'creditcard.fill', android: 'credit_card' },
  savings: { ios: 'chart.line.uptrend.xyaxis', android: 'savings' },
  arrowDown: { ios: 'arrow.down.left', android: 'south_west' },
  arrowUp: { ios: 'arrow.up.right', android: 'north_east' },
  chevronRight: { ios: 'chevron.right', android: 'chevron_right' },
  eye: { ios: 'eye.fill', android: 'visibility' },
  eyeOff: { ios: 'eye.slash.fill', android: 'visibility_off' },
  lock: { ios: 'lock.fill', android: 'lock' },
  shield: { ios: 'checkmark.shield.fill', android: 'verified_user' },
  phone: { ios: 'phone.fill', android: 'call' },
  history: { ios: 'clock.arrow.circlepath', android: 'history' },
  cloudDone: { ios: 'checkmark.icloud.fill', android: 'cloud_done' },
  cloudUpload: { ios: 'icloud.and.arrow.up.fill', android: 'cloud_upload' },
  pending: { ios: 'hourglass', android: 'pending' },
  server: { ios: 'server.rack', android: 'dns' },
  close: { ios: 'xmark', android: 'close' },
  add: { ios: 'plus', android: 'add' },
  calendar: { ios: 'calendar', android: 'calendar_month' },
  reverse: { ios: 'arrow.uturn.backward', android: 'undo' },
  percent: { ios: 'percent', android: 'percent' },
  adjust: { ios: 'slider.horizontal.3', android: 'tune' },
  dayClose: { ios: 'moon.stars.fill', android: 'bedtime' },
  store: { ios: 'storefront.fill', android: 'storefront' },
  chart: { ios: 'chart.bar.fill', android: 'bar_chart' },
  document: { ios: 'doc.richtext.fill', android: 'description' },
  location: { ios: 'location.fill', android: 'location_on' },
  send: { ios: 'arrow.up.circle.fill', android: 'send' },
} as const satisfies Record<string, { ios: SymbolViewProps['name'] & string; android: unknown }>;

export type IconName = keyof typeof ICONS;

type IconProps = {
  name: IconName;
  size?: number;
  color?: ColorValue;
  style?: StyleProp<ViewStyle>;
  /** Icons are decorative by default; pass a label only for standalone meaning. */
  accessibilityLabel?: string;
};

export function Icon({ name, size = 20, color, style, accessibilityLabel }: IconProps) {
  const theme = useTheme();
  const symbol = ICONS[name];

  return (
    <SymbolView
      name={{ ios: symbol.ios, android: symbol.android, web: symbol.android }}
      size={size}
      tintColor={color ?? theme.text}
      style={style}
      accessible={!!accessibilityLabel}
      accessibilityLabel={accessibilityLabel}
      importantForAccessibility={accessibilityLabel ? 'yes' : 'no-hide-descendants'}
    />
  );
}
