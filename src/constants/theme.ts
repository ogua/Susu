/**
 * OguaFinance design tokens — the single source of styling truth.
 * Screens must never hardcode hex values; import from here (or use the
 * components in `src/components/ui`, which already do).
 */

import '@/global.css';

import { Platform } from 'react-native';

/** Brand palette, seeded from the OguaFinance blue (#208AEF). */
export const Palette = {
  primary50: '#eff6ff',
  primary100: '#dbeafe',
  primary500: '#208AEF',
  primary600: '#1a75cc',
  primary700: '#155fa6',
  success: '#16a34a',
  successSoft: '#dcfce7',
  danger: '#dc2626',
  dangerSoft: '#fee2e2',
  warning: '#d97706',
  warningSoft: '#fef3c7',
  neutral: '#6b7280',
  neutralSoft: '#f3f4f6',
} as const;

/** Semantic colors, light/dark. Kept as `Colors` for backwards compatibility. */
export const Colors = {
  light: {
    text: '#111827',
    background: '#f6f7f9',
    backgroundElement: '#ffffff',
    backgroundSelected: '#e5e7eb',
    textSecondary: '#6b7280',
    surface: '#ffffff',
    border: '#e5e7eb',
    primary: Palette.primary500,
    primarySoft: Palette.primary50,
  },
  dark: {
    text: '#f9fafb',
    background: '#0b0f14',
    backgroundElement: '#161b22',
    backgroundSelected: '#21262e',
    textSecondary: '#9ca3af',
    surface: '#161b22',
    border: '#2b313a',
    primary: Palette.primary500,
    primarySoft: '#12233a',
  },
} as const;

export type ThemeColor = keyof typeof Colors.light & keyof typeof Colors.dark;

export const Fonts = Platform.select({
  ios: {
    /** iOS `UIFontDescriptorSystemDesignDefault` */
    sans: 'system-ui',
    /** iOS `UIFontDescriptorSystemDesignSerif` */
    serif: 'ui-serif',
    /** iOS `UIFontDescriptorSystemDesignRounded` */
    rounded: 'ui-rounded',
    /** iOS `UIFontDescriptorSystemDesignMonospaced` */
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
 * Type scale. The old 48px "title" read like a splash screen inside list
 * screens; this scale tops out at 28 and fills the previously missing
 * 18–24 range that headings and money amounts actually need.
 */
export const Type = {
  title: { fontSize: 28, lineHeight: 34, fontWeight: '700' },
  heading: { fontSize: 20, lineHeight: 26, fontWeight: '600' },
  body: { fontSize: 16, lineHeight: 24, fontWeight: '500' },
  small: { fontSize: 13, lineHeight: 18, fontWeight: '500' },
} as const;

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
  pill: 999,
} as const;

/** Soft elevation used by cards/tiles; Android maps it to `elevation`. */
export const Shadow = Platform.select({
  android: { elevation: 2 },
  default: {
    shadowColor: '#0f172a',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.08,
    shadowRadius: 8,
  },
}) as object;

export const BottomTabInset = Platform.select({ ios: 50, android: 80 }) ?? 0;
export const MaxContentWidth = 800;
