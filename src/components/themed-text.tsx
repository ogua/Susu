import { Platform, StyleSheet, Text, type TextProps } from 'react-native';

import { Fonts, Type, type ThemeColor } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export type ThemedTextType =
  | 'default'
  | 'display'
  | 'title'
  | 'subtitle'
  | 'heading'
  | 'bodyStrong'
  | 'label'
  | 'small'
  | 'smallBold'
  | 'caption'
  | 'money'
  | 'moneyLarge'
  | 'moneyHero'
  | 'link'
  | 'linkPrimary'
  | 'code';

export type ThemedTextProps = TextProps & {
  type?: ThemedTextType;
  themeColor?: ThemeColor;
};

export function ThemedText({ style, type = 'default', themeColor, ...rest }: ThemedTextProps) {
  const theme = useTheme();

  return (
    <Text
      style={[
        { color: theme[themeColor ?? 'text'] },
        styles[type],
        type === 'linkPrimary' && { color: theme.primaryText },
        style,
      ]}
      {...rest}
    />
  );
}

// Sizes come from the token scale in constants/theme.ts (Type). `subtitle`
// and `smallBold` are kept as aliases for older call sites.
const styles = StyleSheet.create({
  default: Type.body,
  display: Type.display,
  title: Type.title,
  subtitle: Type.heading,
  heading: Type.heading,
  bodyStrong: Type.bodyStrong,
  label: Type.label,
  small: Type.small,
  smallBold: { ...Type.small, fontWeight: '700' },
  caption: Type.caption,
  money: Type.money,
  moneyLarge: Type.moneyLarge,
  moneyHero: Type.moneyHero,
  link: { lineHeight: 24, fontSize: 14 },
  linkPrimary: { lineHeight: 24, fontSize: 14, fontWeight: '600' },
  code: {
    fontFamily: Fonts.mono,
    fontWeight: Platform.select({ android: '700', default: '500' }),
    fontSize: 12,
  },
});
