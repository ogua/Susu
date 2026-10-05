import * as ImagePicker from 'expo-image-picker';
import { useEffect, useState } from 'react';
import { Linking, StyleSheet, View } from 'react-native';

import { me, removeProfilePhoto, uploadProfilePhoto } from '@/api/auth';
import { apiErrorMessage } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import { Avatar, Button, Card, confirmAction, KeyValueRow, Notice, Screen } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useOnline } from '@/hooks/use-network';
import { useAuthStore } from '@/stores/authStore';
import { roleLabel } from '@/utils/format';

type Busy = 'upload' | 'remove' | null;

const PICKER_OPTIONS: ImagePicker.ImagePickerOptions = {
  mediaTypes: ['images'],
  allowsEditing: true,
  aspect: [1, 1],
  shape: 'oval',
  quality: 0.7,
};

/**
 * The signed-in staff member's profile. The photo is what supervisors see
 * on the branch's live agent tracking map, so uploading needs a connection
 * (it is not queued in the outbox like collections are).
 */
export default function ProfileScreen() {
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const online = useOnline();
  const [busy, setBusy] = useState<Busy>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);

  const branch = user?.branches?.map((b) => b.name).join(', ');

  useEffect(() => {
    // Refresh so a photo set on the web (or a session cached before photos existed) shows here.
    me().then(setUser).catch(() => undefined);
  }, [setUser]);

  async function pick(source: 'camera' | 'library') {
    setError(null);
    setSaved(null);

    const permission =
      source === 'camera'
        ? await ImagePicker.requestCameraPermissionsAsync()
        : await ImagePicker.requestMediaLibraryPermissionsAsync();

    if (!permission.granted) {
      setError(
        source === 'camera'
          ? 'Camera access is off for SusuApp. Turn it on in Settings to take a photo.'
          : 'Photo access is off for SusuApp. Turn it on in Settings to choose a photo.',
      );
      if (!permission.canAskAgain) {
        void Linking.openSettings();
      }
      return;
    }

    const result =
      source === 'camera'
        ? await ImagePicker.launchCameraAsync({ ...PICKER_OPTIONS, cameraType: ImagePicker.CameraType.front })
        : await ImagePicker.launchImageLibraryAsync(PICKER_OPTIONS);

    if (result.canceled || !result.assets[0]) {
      return;
    }

    setBusy('upload');
    try {
      await setUser(await uploadProfilePhoto(result.assets[0]));
      setSaved('Photo updated. Your branch now sees it on the agent map.');
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  function handleRemove() {
    confirmAction({
      title: 'Remove your photo?',
      message: 'Your branch will see your initials on the agent map instead.',
      confirmLabel: 'Remove photo',
      destructive: true,
      onConfirm: async () => {
        setError(null);
        setSaved(null);
        setBusy('remove');
        try {
          await setUser(await removeProfilePhoto());
          setSaved('Photo removed.');
        } catch (e) {
          setError(apiErrorMessage(e));
        } finally {
          setBusy(null);
        }
      },
    });
  }

  return (
    <Screen>
      <View style={styles.hero}>
        <Avatar name={user?.name ?? ''} uri={user?.photo_url} size={112} />
        <ThemedText type="heading">{user?.name}</ThemedText>
        <ThemedText type="small" themeColor="textSecondary">
          {roleLabel(user?.role)}
          {branch ? ` · ${branch}` : ''}
        </ThemedText>
      </View>

      {!online && (
        <Notice tone="warning" icon="offline" message="You're offline. Connect to the internet to change your photo." />
      )}
      {error && <Notice tone="danger" message={error} />}
      {saved && <Notice tone="success" message={saved} />}

      <Card style={styles.actions}>
        <ThemedText type="label">Profile photo</ThemedText>
        <ThemedText type="caption" themeColor="textMuted">
          Use a clear photo of your face. Supervisors use it to recognise you on the agent tracking map.
        </ThemedText>
        <Button
          title="Take a photo"
          icon="camera"
          loading={busy === 'upload'}
          loadingTitle="Uploading…"
          disabled={!online || busy !== null}
          onPress={() => void pick('camera')}
        />
        <Button
          title="Choose from gallery"
          icon="photoLibrary"
          variant="outline"
          disabled={!online || busy !== null}
          onPress={() => void pick('library')}
        />
        {user?.photo_url ? (
          <Button
            title="Remove photo"
            icon="trash"
            variant="ghost"
            loading={busy === 'remove'}
            loadingTitle="Removing…"
            disabled={!online || busy !== null}
            onPress={handleRemove}
          />
        ) : null}
      </Card>

      <Card>
        <KeyValueRow label="Email" value={user?.email ?? '—'} />
        <KeyValueRow label="Phone" value={user?.phone ?? '—'} />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { alignItems: 'center', gap: Spacing.one, paddingVertical: Spacing.three },
  actions: { gap: Spacing.two },
});
