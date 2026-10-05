/**
 * OguaFinance design tokens — the single source of styling truth.
 * Screens must never hardcode hex values; read semantic colours from
 * `useTheme()` (or use the components in `src/components/ui`, which do).
 *
 * The palette anchors on a deep "bank" navy for trust, keeps the original
 * OguaFinance blue (#208AEF) as an accent, and reserves green/red strictly
 * for money in/out and success/failure.
 */

import '@/global.css';

import { Platform, type TextStyle } from 'react-native';

/** Raw brand ramp. Prefer the semantic `Colors` below in screens. */
export const Palette = {
  navy900: '#0B2257',
  navy800: '#0E2F73',
  navy700: '#123A87',
  navy600: '#1849A9',
  navy100: '#E8EEFB',
  accent: '#208AEF',
  // Back-compat aliases still referenced by a few charts.
  primary50: '#E8EEFB',
  primary100: '#D3E0F8',
  primary500: '#1849A9',
  primary600: '#123A87',
  primary700: '#0E2F73',
  success: '#047857',
  successSoft: '#D1FAE5',
  danger: '#B91C1C',
  dangerSoft: '#FEE2E2',
  warning: '#B45309',
  warningSoft: '#FEF3C7',
  neutral: '#64748B',
  neutralSoft: '#F1F5F9',
  border: '#E2E8F0',
  white: '#FFFFFF',
} as const;

const light = {
  text: '#0F172A',
  textSecondary: '#475569',
  textMuted: '#64748B',
  background: '#F4F6FA',
  backgroundElement: '#FFFFFF',
  backgroundSelected: '#E2E8F0',
  surface: '#FFFFFF',
  surfaceMuted: '#F1F5F9',
  border: '#E2E8F0',
  borderStrong: '#CBD5E1',
  primary: '#1849A9',
  primaryPressed: '#123A87',
  primarySoft: '#E8EEFB',
  /** Primary used as text/icon on surfaces (contrast-safe in both modes). */
  primaryText: '#1849A9',
  onPrimary: '#FFFFFF',
  success: '#047857',
  successSoft: '#D1FAE5',
  warning: '#B45309',
  warningSoft: '#FEF3C7',
  danger: '#B91C1C',
  dangerSoft: '#FEE2E2',
  /** Solid fill for destructive buttons (white text on top). */
  dangerStrong: '#B91C1C',
  info: '#1D4ED8',
  infoSoft: '#DBEAFE',
  neutral: '#475569',
  neutralSoft: '#F1F5F9',
  heroStart: '#1849A9',
  heroEnd: '#0B2257',
  chartLine: '#1849A9',
  chartFill: '#D3E0F8',
};

const dark: typeof light = {
  text: '#F1F5F9',
  textSecondary: '#AAB6C8',
  textMuted: '#8391A7',
  background: '#0A0F1A',
  backgroundElement: '#131B2B',
  backgroundSelected: '#1E2A3F',
  surface: '#131B2B',
  surfaceMuted: '#1A2436',
  border: '#24314A',
  borderStrong: '#334463',
  primary: '#2F6BE0',
  primaryPressed: '#2459C2',
  primarySoft: '#16264A',
  primaryText: '#8CB2FF',
  onPrimary: '#FFFFFF',
  success: '#34D399',
  successSoft: '#0B2E24',
  warning: '#FBBF24',
  warningSoft: '#33260A',
  danger: '#F87171',
  dangerSoft: '#3A1414',
  dangerStrong: '#DC2626',
  info: '#93C5FD',
  infoSoft: '#13254A',
  neutral: '#AAB6C8',
  neutralSoft: '#1A2436',
  heroStart: '#1C3F8F',
  heroEnd: '#0B1E4A',
  chartLine: '#8CB2FF',
  chartFill: '#16264A',
};

/** Semantic colors, light/dark. */
export const Colors = { light, dark } as const;

export type ThemeColor = keyof typeof light;
export type ThemeColors = typeof light;

export const Fonts = Platform.select({
  ios: {
    sans: 'system-ui',
    serif: 'ui-serif',
    rounded: 'ui-rounded',
    mono: 'ui-monospace',
  },
  default: {
    sans: 'normal',
    serif: 'serif',
    rounded: 'normal',
    mono: 'monospace',
  },
  web: {
    sans: 'var(--font-display)',
    serif: 'var(--font-serif)',
    rounded: 'var(--font-rounded)',
    mono: 'var(--font-mono)',
  },
});

/**
 * Type scale. Money styles use tabular figures so columns of amounts line
 * up and digits don't jitter while a balance animates or is typed.
 */
export const Type = {
  display: { fontSize: 32, lineHeight: 38, fontWeight: '700' },
  title: { fontSize: 24, lineHeight: 30, fontWeight: '700' },
  heading: { fontSize: 18, lineHeight: 24, fontWeight: '600' },
  body: { fontSize: 16, lineHeight: 24, fontWeight: '400' },
  bodyStrong: { fontSize: 16, lineHeight: 24, fontWeight: '600' },
  label: { fontSize: 14, lineHeight: 20, fontWeight: '600' },
  small: { fontSize: 13, lineHeight: 18, fontWeight: '500' },
  caption: { fontSize: 12, lineHeight: 16, fontWeight: '500' },
  moneyHero: { fontSize: 34, lineHeight: 42, fontWeight: '700', fontVariant: ['tabular-nums'] },
  moneyLarge: { fontSize: 24, lineHeight: 30, fontWeight: '700', fontVariant: ['tabular-nums'] },
  money: { fontSize: 16, lineHeight: 22, fontWeight: '600', fontVariant: ['tabular-nums'] },
} satisfies Record<string, TextStyle>;

/** 4pt spacing grid. */
export const Spacing = {
  half: 2,
  one: 4,
  two: 8,
  three: 16,
  four: 24,
  five: 32,
  six: 64,
} as const;

export const Radii = {
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  pill: 999,
} as const;

/** Soft elevation used by cards/tiles; Android maps it to `elevation`. */
export const Shadow = Platform.select({
  android: { elevation: 1 },
  default: {
    shadowColor: '#0f172a',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.06,
    shadowRadius: 6,
  },
}) as object;

/** Stronger elevation for floating elements (toasts, sticky footers). */
export const ShadowFloating = Platform.select({
  android: { elevation: 6 },
  default: {
    shadowColor: '#0f172a',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.16,
    shadowRadius: 16,
  },
}) as object;

/** Minimum touch target (Material 48dp / Apple 44pt). */
export const MinTouch = 48;

export const BottomTabInset = Platform.select({ ios: 50, android: 80 }) ?? 0;
export const MaxContentWidth = 800;
