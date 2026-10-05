import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, ZoomIn } from 'react-native-reanimated';

import { ThemedText } from '@/components/themed-text';
import { Card } from '@/components/ui/Card';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ResultTone = 'success' | 'pending' | 'failed';

/**
 * Full-width outcome panel after a financial action: big status icon,
 * amount, an honest status line, and an optional receipt card. "pending" is
 * used for outbox writes the server hasn't confirmed yet — it never claims
 * the server has the record.
 */
export function ResultView({
  tone,
  title,
  amount,
  message,
  children,
}: {
  tone: ResultTone;
  title: string;
  amount?: string;
  message?: string;
  children?: React.ReactNode;
}) {
  const theme = useTheme();
  const visual: Record<ResultTone, { icon: IconName; fg: string; bg: string }> = {
    success: { icon: 'checkCircle', fg: theme.success, bg: theme.successSoft },
    pending: { icon: 'cloudUpload', fg: theme.warning, bg: theme.warningSoft },
    failed: { icon: 'error', fg: theme.danger, bg: theme.dangerSoft },
  };
  const { icon, fg, bg } = visual[tone];

  return (
    <View style={styles.wrapper} accessibilityLiveRegion="assertive">
      <Animated.View entering={ZoomIn.duration(220)} style={[styles.iconCircle, { backgroundColor: bg }]}>
        <Icon name={icon} size={44} color={fg} />
      </Animated.View>
      <Animated.View entering={FadeInDown.duration(220).delay(60)} style={styles.texts}>
        <ThemedText type="heading" style={styles.center} accessibilityRole="header">
          {title}
        </ThemedText>
        {amount ? (
          <ThemedText type="moneyHero" style={styles.center} adjustsFontSizeToFit numberOfLines={1}>
            {amount}
          </ThemedText>
        ) : null}
        {message ? (
          <ThemedText type="small" themeColor="textSecondary" style={[styles.center, styles.message]}>
            {message}
          </ThemedText>
        ) : null}
      </Animated.View>
      {children ? (
        <Animated.View entering={FadeInDown.duration(240).delay(120)} style={styles.receiptWrap}>
          {children}
        </Animated.View>
      ) : null}
    </View>
  );
}

/** Receipt-style card: business header, dashed divider, label/value rows. */
export function ReceiptCard({
  business,
  title = 'Collection receipt',
  children,
  footnote,
}: {
  business?: string;
  title?: string;
  children: React.ReactNode;
  footnote?: string;
}) {
  const theme = useTheme();

  return (
    <Card style={styles.receipt}>
      <View style={styles.receiptHeader}>
        <View style={[styles.logo, { backgroundColor: theme.primary }]}>
          <Icon name="bank" size={16} color={theme.onPrimary} />
        </View>
        <View style={{ flex: 1 }}>
          <ThemedText type="label">{business || 'OguaFinance'}</ThemedText>
          <ThemedText type="caption" themeColor="textMuted">
            {title}
          </ThemedText>
        </View>
      </View>
      <View style={[styles.dashed, { borderColor: theme.borderStrong }]} />
      {children}
      {footnote ? (
        <>
          <View style={[styles.dashed, { borderColor: theme.borderStrong }]} />
          <ThemedText type="caption" themeColor="textMuted" style={styles.center}>
            {footnote}
          </ThemedText>
        </>
      ) : null}
    </Card>
  );
}

const styles = StyleSheet.create({
  wrapper: { alignItems: 'center', gap: Spacing.three, paddingTop: Spacing.four },
  iconCircle: {
    width: 88,
    height: 88,
    borderRadius: Radii.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  texts: { alignItems: 'center', gap: Spacing.one, alignSelf: 'stretch' },
  center: { textAlign: 'center' },
  message: { maxWidth: 340 },
  receiptWrap: { alignSelf: 'stretch' },
  receipt: { gap: 2 },
  receiptHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 6 },
  logo: { width: 32, height: 32, borderRadius: Radii.sm, alignItems: 'center', justifyContent: 'center' },
  dashed: { borderTopWidth: 1, borderStyle: 'dashed', marginVertical: 8 },
});
