# App Update / Version Gate — Implementation Reference

A portable spec for adding a **"please update the mobile app" prompt** to a Laravel (+ Filament)
backend and an Expo / React Native client. Written from the OguaPOS implementation so other projects
(school app, susu app, desktop-adjacent clients) can reuse the same contract instead of re-inventing
one.

There is **no OTA / code-push** here. `expo-updates` is not installed; every JS change ships as a
store build. This feature is a *prompting layer* only — it tells the user a newer binary exists (or
that theirs is too old to run), and sends them to the store. It never downloads or applies anything.

---

## 1. Architecture at a glance

```
┌─────────────── Expo app ───────────────┐         ┌──────────── Laravel API ────────────┐
│ APP_VERSION (expo.version, build-time)  │         │  GET /api/v1/app/update-check        │
│            │                            │  HTTP   │      ?platform=android&version=1.2.3 │
│  useAppUpdate() ── appUpdateStore ──────┼────────▶│  AppUpdateController::check()        │
│            │            │               │         │      │                              │
│  AppUpdateGate      AppVersionFooter    │         │  app_versions table (Superadmin UI) │
│  (root modal +      (Settings / More    │         │      └─ fallback: config/mobileapp  │
│   1×/session banner)  version line)     │         │                                     │
└────────────────────────────────────────┘         └─────────────────────────────────────┘
```

- **Public endpoint** — deliberately outside the `auth:sanctum` / subscription middleware group, so a
  logged-out device, or one whose subscription has lapsed (402 everywhere else), can still be told it
  must update. Nothing tenant-specific is exposed: an app binary is global.
- **Client does the version math** (plain `major.minor.patch`, integer-parsed, no semver
  pre-release). The endpoint only reports policy.
- **Two enforcement levels**: a dismissible "update available" prompt (newer version exists) and a
  blocking, non-dismissible screen (`force_update`, or the running build is below `minimum_version`).

---

## 2. The API contract (do not break silently)

`GET /api/v1/app/update-check`

**Query params** (all optional; the client sends the first two):

| param | example | meaning |
|---|---|---|
| `platform` | `android` \| `ios` | which store build the caller wants policy for |
| `version` | `1.2.3` | the caller's running `expo.version` — used to gate `force_update` |

**Response** — always `200`, always this shape:

```json
{
  "data": {
    "latest_version":    "1.4.2",
    "minimum_version":   "1.2.0",
    "force_update":      false,
    "update_message":    "Bug fixes and speed-ups.",
    "store_url_android": "https://play.google.com/store/apps/details?id=…",
    "store_url_ios":     "https://apps.apple.com/app/id…",
    "platform":          "android"
  }
}
```

| field | type | client uses it for |
|---|---|---|
| `latest_version` | string | newer than `APP_VERSION` → dismissible prompt |
| `minimum_version` | string | `APP_VERSION` below it → blocking screen (hard floor) |
| `force_update` | bool | `true` → blocking screen regardless of version math |
| `update_message` | string \| null | copy in both surfaces; client falls back to default copy |
| `store_url_android` / `store_url_ios` | string \| null | the "Update" button target; **always send the one matching a forced update or the user is stranded** |
| `platform` | `"android"` \| `"ios"` \| null | echo of the query, so the client can confirm it was understood |

**Failure handling is entirely client-side**: any non-200 / offline / timeout → the client treats it
as "no policy" and shows no prompt. The endpoint never needs to return an error status.

---

## 3. Backend — DB-backed policy (recommended)

Managed from a Superadmin Filament page so publishing a release is a form, not an SSH + `.env` edit +
`php artisan config:cache`. Falls back to `config/mobileapp.php` when the table has no active row
(fresh install, or you never adopt the table).

Modelled on oguaschoolv2's `AppVersion` (`app/Models/AppVersion.php`,
`database/migrations/2024_01_22_create_app_versions_table.php`,
`app/Http/Controllers/Api/V2/AppVersionController.php`,
`app/Filament/Superadmin/Resources/AppVersions/`). Simplified: **one mobile app ⇒ no `app_type`
column**, only `device_os`. If your project ships multiple mobile apps (student/parent/staff), keep
`app_type` as an `enum` and add it to the unique key, the query `where`, and the Filament form/table.

### 3.1 Migration

```php
<?php
// database/migrations/xxxx_xx_xx_create_app_versions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->enum('device_os', ['android', 'ios']);
            $table->string('version', 20);                       // human string, e.g. "1.4.2"
            $table->unsignedInteger('version_code');             // the real ordering key
            $table->string('minimum_supported_version', 20)->nullable();
            $table->boolean('is_forced_update')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('release_notes')->nullable();           // -> update_message
            $table->string('store_url')->nullable();             // this platform's store link
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['device_os', 'version']);
            $table->index(['device_os', 'is_active', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
```

Why `version_code` and not just `version`: `version_compare()` on the string works, but an explicit
incrementing integer is unambiguous for "which row is newest" and matches what Expo (`android.versionCode`
/ `ios.buildNumber`) and both app stores already use.

### 3.2 Model

```php
<?php
// app/Models/AppVersion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    protected $fillable = [
        'device_os', 'version', 'version_code', 'minimum_supported_version',
        'is_forced_update', 'is_active', 'release_notes', 'store_url', 'released_at',
    ];

    protected $casts = [
        'version_code'     => 'integer',
        'is_forced_update' => 'boolean',
        'is_active'        => 'boolean',
        'released_at'      => 'datetime',
    ];

    /** The active build with the highest version_code for a platform, or null. */
    public static function latestActiveFor(string $deviceOs): ?self
    {
        return static::query()
            ->where('device_os', $deviceOs)
            ->where('is_active', true)
            ->orderByDesc('version_code')
            ->first();
    }
}
```

### 3.3 Controller

```php
<?php
// app/Http/Controllers/Api/V1/AppUpdateController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AppUpdateController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $platform = $request->query('platform');
        $os = in_array($platform, ['android', 'ios'], true) ? $platform : null;

        $row = $os && Schema::hasTable('app_versions')
            ? AppVersion::latestActiveFor($os)
            : null;

        if ($row) {
            $current = (string) $request->query('version', '0.0.0');
            $updateAvailable = version_compare($row->version, $current, '>');

            return response()->json(['data' => [
                'latest_version'  => (string) $row->version,
                'minimum_version' => (string) ($row->minimum_supported_version ?: $row->version),
                // Only force when the row is genuinely newer than the caller — an up-to-date
                // device is never hard-blocked by the flag. (A device below minimum_version
                // still is, client-side; that's the intended hard floor.)
                'force_update'    => $updateAvailable && $row->is_forced_update,
                'update_message'  => $row->release_notes,
                'store_url_android' => $os === 'android'
                    ? ($row->store_url ?: config('mobileapp.store_url_android'))
                    : config('mobileapp.store_url_android'),
                'store_url_ios' => $os === 'ios'
                    ? ($row->store_url ?: config('mobileapp.store_url_ios'))
                    : config('mobileapp.store_url_ios'),
                'platform' => $os,
            ]]);
        }

        // Fallback: env-backed config, unchanged behaviour.
        return response()->json(['data' => [
            'latest_version'    => (string) config('mobileapp.latest_version'),
            'minimum_version'   => (string) config('mobileapp.minimum_version'),
            'force_update'      => (bool) config('mobileapp.force_update'),
            'update_message'    => config('mobileapp.update_message'),
            'store_url_android' => config('mobileapp.store_url_android'),
            'store_url_ios'     => config('mobileapp.store_url_ios'),
            'platform'          => $os,
        ]]);
    }
}
```

### 3.4 Config fallback

```php
<?php
// config/mobileapp.php  — now only a fallback; prefer adding an app_versions row.
return [
    'latest_version'  => env('MOBILE_APP_LATEST_VERSION', '1.0.1'),
    'minimum_version' => env('MOBILE_APP_MINIMUM_VERSION', '1.0.0'),
    'force_update'    => filter_var(env('MOBILE_APP_FORCE_UPDATE', false), FILTER_VALIDATE_BOOL),
    'update_message'  => env('MOBILE_APP_UPDATE_MESSAGE'),
    'store_url_android' => env('MOBILE_APP_STORE_URL_ANDROID',
        'https://play.google.com/store/apps/details?id=com.your.app'),
    'store_url_ios'   => env('MOBILE_APP_STORE_URL_IOS'),
];
```

> **Config-only variant**: if you don't want a Superadmin page at all, ship just this file + a
> controller that reads only `config('mobileapp.*')`. That's what OguaPOS ran before adopting the
> table. The `force_update` flag is then trusted raw by the client, so only flip it for a genuine
> emergency and clear it immediately after the pull.

### 3.5 Route

```php
// routes/api.php
use App\Http\Controllers\Api\V1\AppUpdateController;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    // PUBLIC — outside the auth/subscription group on purpose.
    Route::get('app/update-check', [AppUpdateController::class, 'check']);

    Route::middleware(['auth:sanctum', /* subscription, tenancy … */])->group(function () {
        // everything else
    });
});
```

### 3.6 Filament Superadmin resource

Single-file resource (form + table inline) + a `Pages/` subfolder — match whatever your other
resources use. Navigation: group `Settings`, phone icon.

```php
<?php
// app/Filament/Superadmin/Resources/AppVersionResource.php
namespace App\Filament\Superadmin\Resources;

use App\Filament\Superadmin\Resources\AppVersionResource\Pages;
use App\Models\AppVersion;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class AppVersionResource extends Resource
{
    protected static ?string $model = AppVersion::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';
    protected static string|UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Mobile App Versions';
    protected static ?string $modelLabel = 'Mobile App Version';
    protected static ?int $navigationSort = 95;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Build')->columns(2)->schema([
                Forms\Components\Select::make('device_os')->label('Platform')
                    ->options(['android' => 'Android', 'ios' => 'iOS'])->required(),
                Forms\Components\TextInput::make('version')->label('Version string')
                    ->placeholder('1.2.0')->helperText("Must match expo.version in app.json.")
                    ->required()->maxLength(20),
                Forms\Components\TextInput::make('version_code')->label('Version code')
                    ->helperText('Incrementing integer — higher = newer. The ordering key.')
                    ->required()->numeric()->minValue(1),
                Forms\Components\TextInput::make('minimum_supported_version')
                    ->label('Minimum supported version')->placeholder('1.0.0')
                    ->helperText('Builds older than this get the blocking screen.')->maxLength(20),
                Forms\Components\DateTimePicker::make('released_at')->label('Released at')->default(now()),
            ]),
            Section::make('Rollout')->columns(2)->schema([
                Forms\Components\Toggle::make('is_active')->label('Active')
                    ->helperText('Only the active row with the highest version code is served.')->default(true),
                Forms\Components\Toggle::make('is_forced_update')->label('Force update')
                    ->helperText('Blocks older devices. Devices already on this version are unaffected.')->default(false),
                Forms\Components\TextInput::make('store_url')->label('Store URL')
                    ->placeholder('https://play.google.com/store/apps/details?id=…')
                    ->url()->required()->columnSpanFull(),
                Forms\Components\Textarea::make('release_notes')->label('Update message / release notes')
                    ->helperText('Shown inside the update prompt. Optional.')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('Version')->weight('bold')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('version_code')->label('Code')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('device_os')->label('Platform')->badge()
                    ->color(fn (string $state) => $state === 'ios' ? 'gray' : 'success'),
                Tables\Columns\IconColumn::make('is_forced_update')->label('Force')->boolean()
                    ->trueColor('danger')->falseColor('gray'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\TextColumn::make('minimum_supported_version')->label('Min')->placeholder('—'),
                Tables\Columns\TextColumn::make('released_at')->label('Released')->dateTime('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('version_code', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('device_os')->label('Platform')
                    ->options(['android' => 'Android', 'ios' => 'iOS']),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_forced_update')->label('Force update'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAppVersions::route('/'),
            'create' => Pages\CreateAppVersion::route('/create'),
            'edit'   => Pages\EditAppVersion::route('/{record}/edit'),
        ];
    }
}
```

```php
// Pages/ListAppVersions.php
class ListAppVersions extends \Filament\Resources\Pages\ListRecords {
    protected static string $resource = AppVersionResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }
}
// Pages/CreateAppVersion.php
class CreateAppVersion extends \Filament\Resources\Pages\CreateRecord {
    protected static string $resource = AppVersionResource::class;
}
// Pages/EditAppVersion.php
class EditAppVersion extends \Filament\Resources\Pages\EditRecord {
    protected static string $resource = AppVersionResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\DeleteAction::make()]; }
}
```

Panel access: OguaPOS controls this with `User::canAccessPanel()` (`'superadmin' => $role === 'superadmin'`),
no per-resource policy. If your project uses `filament-shield`, generate a policy/permission for
`AppVersion` like the other Superadmin resources.

---

## 4. Mobile client — Expo / React Native

Six files + wiring. All paths under `src/`.

### 4.1 `config/appVersion.ts` — the local half of the compare

```ts
import Constants from 'expo-constants';

/** Running build's version, from expo.version in app.json (via expo-constants). No OTA in this app. */
export const APP_VERSION: string = Constants.expoConfig?.version ?? '1.0.0';

/** Numeric major.minor.patch compare. Missing segments -> 0, non-numeric -> 0.
 *  Negative: current older than target. 0: equivalent. Positive: current newer. */
export function compareVersions(current: string, target: string): number {
  const parse = (v: string): number[] =>
    String(v ?? '').split('.').slice(0, 3).map((p) => {
      const n = Number.parseInt(p, 10);
      return Number.isFinite(n) ? n : 0;
    });
  const a = parse(current);
  const b = parse(target);
  for (let i = 0; i < 3; i += 1) {
    const diff = (a[i] ?? 0) - (b[i] ?? 0);
    if (diff !== 0) return diff < 0 ? -1 : 1;
  }
  return 0;
}
```

### 4.2 `api/appUpdate.ts` — typed wrapper, fire-and-forget

```ts
import { Platform } from 'react-native';
import { api } from '@/api/client';
import { APP_VERSION } from '@/config/appVersion';

export interface AppUpdateInfo {
  latestVersion: string;
  minimumVersion: string;
  forceUpdate: boolean;
  updateMessage: string | null;
  storeUrlAndroid: string | null;
  storeUrlIos: string | null;
}

interface RawAppUpdateInfo {
  latest_version?: string;
  minimum_version?: string;
  force_update?: boolean;
  update_message?: string | null;
  store_url_android?: string | null;
  store_url_ios?: string | null;
}

/** Any failure (offline, timeout, 5xx) -> null, and the caller shows no prompt. */
export async function fetchAppUpdateInfo(): Promise<AppUpdateInfo | null> {
  try {
    const platform = Platform.OS === 'ios' ? 'ios' : 'android';
    const { data } = await api.get<{ data: RawAppUpdateInfo }>('/app/update-check', {
      params: { platform, version: APP_VERSION },
    });
    const raw = data?.data;
    if (!raw || typeof raw.latest_version !== 'string') return null;
    return {
      latestVersion: raw.latest_version,
      minimumVersion: typeof raw.minimum_version === 'string' ? raw.minimum_version : raw.latest_version,
      forceUpdate: raw.force_update === true,
      updateMessage: raw.update_message ?? null,
      storeUrlAndroid: raw.store_url_android ?? null,
      storeUrlIos: raw.store_url_ios ?? null,
    };
  } catch {
    return null;
  }
}
```

### 4.3 `stores/appUpdateStore.ts` — Zustand; decides *whether to nag*

Two kinds of state, kept apart:

- **`dismissedVersion`** (persisted, `expo-secure-store`) — the exact `latestVersion` the user tapped
  away. An optional prompt counts as dismissed only while this equals the current `latestVersion`, so
  a newer release re-prompts. A forced update ignores it.
- **`info` / `checkedThisSession`** (in-memory) — last payload + whether the server was asked this
  run. `check()` is a no-op once checked unless `force` is passed (the foreground-return refresh
  passes it).

```ts
import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';
import { fetchAppUpdateInfo, type AppUpdateInfo } from '@/api/appUpdate';

const DISMISSED_KEY = 'yourapp.appUpdate.dismissedVersion';

interface AppUpdateState {
  hydrated: boolean;
  checkedThisSession: boolean;
  loading: boolean;
  info: AppUpdateInfo | null;
  dismissedVersion: string | null;
  hydrate: () => Promise<void>;
  check: (force?: boolean) => Promise<void>;
  dismiss: () => Promise<void>;
}

export const useAppUpdateStore = create<AppUpdateState>((set, get) => ({
  hydrated: false,
  checkedThisSession: false,
  loading: false,
  info: null,
  dismissedVersion: null,

  hydrate: async () => {
    if (get().hydrated) return;
    const dismissed = await SecureStore.getItemAsync(DISMISSED_KEY);
    set({ hydrated: true, dismissedVersion: dismissed ?? null });
  },

  check: async (force = false) => {
    if (get().loading) return;
    if (get().checkedThisSession && !force) return;
    set({ loading: true });
    try {
      const info = await fetchAppUpdateInfo();
      // A null result (offline / error) keeps any earlier good payload rather than blanking it.
      set({ checkedThisSession: true, info: info ?? get().info });
    } finally {
      set({ loading: false });
    }
  },

  dismiss: async () => {
    const latest = get().info?.latestVersion;
    if (!latest) return;
    await SecureStore.setItemAsync(DISMISSED_KEY, latest);
    set({ dismissedVersion: latest });
  },
}));
```

> If a "reset device / wipe" flow exists, it must **not** clear `dismissedVersion` — it's keyed to
> the app binary, not the account.

### 4.4 `hooks/useAppUpdate.ts` — composes the booleans the UI needs

```ts
import { useCallback } from 'react';
import { Linking, Platform } from 'react-native';
import { APP_VERSION, compareVersions } from '@/config/appVersion';
import { useAppConfigStore } from '@/stores/appConfigStore';   // your "app mode" store, if any
import { useAppUpdateStore } from '@/stores/appUpdateStore';

export function useAppUpdate() {
  const mode = useAppConfigStore((s) => s.mode);               // 'offline' | 'online' | 'hybrid'
  const info = useAppUpdateStore((s) => s.info);
  const loading = useAppUpdateStore((s) => s.loading);
  const dismissedVersion = useAppUpdateStore((s) => s.dismissedVersion);
  const dismiss = useAppUpdateStore((s) => s.dismiss);
  const check = useAppUpdateStore((s) => s.check);

  const latestVersion = info?.latestVersion ?? null;
  const storeUrl = info ? (Platform.OS === 'ios' ? info.storeUrlIos : info.storeUrlAndroid) : null;

  const updateAvailable = Boolean(latestVersion) && compareVersions(APP_VERSION, latestVersion!) < 0;
  const belowMinimum = Boolean(info) && compareVersions(APP_VERSION, info!.minimumVersion) < 0;
  // Offline mode never has `info`; guard anyway so a stale payload can't block an offline run.
  const forceUpdate = mode !== 'offline' && Boolean(info) && (info!.forceUpdate || belowMinimum);

  const isDismissed = Boolean(latestVersion) && dismissedVersion === latestVersion;

  const openStore = useCallback(() => { if (storeUrl) void Linking.openURL(storeUrl); }, [storeUrl]);
  const checkNow = useCallback((force?: boolean) => check(force), [check]);

  return {
    currentVersion: APP_VERSION, latestVersion, updateAvailable, forceUpdate,
    storeUrl, updateMessage: info?.updateMessage ?? null, isDismissed, loading,
    openStore, dismiss, checkNow,
  };
}
```

If your app has no "mode" concept, drop the `mode` guard — `forceUpdate` is just
`Boolean(info) && (info.forceUpdate || belowMinimum)`.

### 4.5 `components/AppUpdateGate.tsx` — the two active surfaces

Mounted **once at the app root**, above every navigator.

- **Force-update modal** — full-screen, non-dismissible `<Modal>` whenever `forceUpdate`. Only action
  is "Update now" → `openStore()`. If `storeUrl` is null the button is replaced with a "update from
  your app store" hard-stop message (so a mis-configured backend can't fully brick the app with a
  dead button, but it's still blocked).
- **Optional-update banner** — slim, dismissible bar, shown **once per app session** for a plain
  newer release (`updateAvailable && !isDismissed && !bannerHiddenThisSession && storeUrl`). Tapping
  × calls `dismiss()` (permanent for that exact version) and hides it for the session.

Check timing:

```ts
// on mount, after config + store hydrate, unless offline:
useEffect(() => {
  if (!configHydrated) return;
  let cancelled = false;
  (async () => {
    await hydrate();
    if (cancelled || mode === 'offline') return;
    await checkNow(false);
  })().catch(() => {});
  return () => { cancelled = true; };
}, [hydrate, checkNow, mode, configHydrated]);

// and again on every foreground return (force refresh):
useEffect(() => {
  if (!configHydrated || mode === 'offline') return;
  const sub = AppState.addEventListener('change', (s) => { if (s === 'active') void checkNow(true); });
  return () => sub.remove();
}, [checkNow, mode, configHydrated]);
```

Full component: see `src/components/AppUpdateGate.tsx` in this repo (≈150 lines, mostly styles).

### 4.6 `components/AppVersionFooter.tsx` — the permanent line

Always renders `Version x.y.z`. When a non-forced newer release exists and a `storeUrl` is known, it
also renders a tappable "Update to x.y.z available" row. This is the standing re-entry point after
the one-shot banner is gone. Placed in Settings and/or a "More" menu, in **every** mode (an offline
device still wants to see its version).

```tsx
export function AppVersionFooter() {
  const { currentVersion, latestVersion, updateAvailable, forceUpdate, storeUrl, openStore } = useAppUpdate();
  const showUpdateRow = updateAvailable && !forceUpdate && Boolean(storeUrl);
  return (
    <View style={styles.wrap}>
      {showUpdateRow ? (
        <ListRow icon="arrow-up-circle-outline" title={`Update to ${latestVersion} available`}
                 subtitle="Tap to open your app store" showChevron onPress={openStore} />
      ) : null}
      <ThemedText type="small" themeColor="textSecondary" style={styles.version}>
        Version {currentVersion}
      </ThemedText>
    </View>
  );
}
```

### 4.7 Wiring

```tsx
// src/app/_layout.tsx  (expo-router root)
import { AppUpdateGate } from '@/components/AppUpdateGate';

export default function RootLayout() {
  return (
    <ThemeProvider …>
      <Stack screenOptions={{ headerShown: false }} />
      <AppUpdateGate />          {/* sits above every navigator */}
    </ThemeProvider>
  );
}
```

```tsx
// src/app/(app)/settings.tsx  and  src/app/(app)/more.tsx
import { AppVersionFooter } from '@/components/AppVersionFooter';
// … render <AppVersionFooter /> at the bottom of the screen.
```

---

## 5. Design decisions & deviations

| decision | why |
|---|---|
| **Public endpoint** (no auth / subscription middleware) | a logged-out or lapsed device must still be tellable to update |
| **Client does version math** | keeps the endpoint a dumb policy reporter; one compare implementation to test (`compareVersions`) |
| **`version_code` int as the sort key** | unambiguous "newest row"; mirrors Expo + both stores |
| **`force_update` gated by `version_compare(row, caller)` server-side** | a device already on the newest build is never hard-blocked by an admin leaving the flag on |
| **`minimum_version` evaluated client-side** | a real hard floor that doesn't depend on someone remembering to also flip `force_update`. *(The reference impl this was ported from left `minimum_version` fetched-but-unused.)* |
| **Whole gate skipped in Offline mode** | an offline-by-design app never contacts the server after setup; a prompt there is impossible. It relies on the store's own auto-update, and still shows its version in Settings. |
| **`dismissedVersion` persisted, keyed to `latestVersion`** | dismiss is per-release: a newer version re-prompts; a device wipe must not clear it |
| **Null payload never blanks a good one** | one flaky poll shouldn't make a real forced-update prompt vanish |
| **Config fallback kept** | endpoint works before any DB row exists; also the entire feature can run config-only |
| **Store URL falls back to config when a row omits it** | a forced-update device is never stranded with a null "Update" target |

---

## 6. Tests

### Backend (Pest) — `tests/Feature/Api/V1/AppUpdateCheckTest.php`

- reachable without a token; response has the full `data` structure
- returns the configured policy (config-fallback path) for each field
- `force_update` reflects config on the fallback path
- unknown `platform` → `data.platform` is null
- **DB path**: an active row is served in preference to config
- highest `version_code` among active rows wins
- inactive rows are ignored → falls back to config
- one platform's row is not served to the other platform
- `force_update` is `true` only when the row is newer than `?version=` (same call with `version` ==
  row version → `false`)
- `store_url` falls back to config when the row's is null
- `minimum_version` defaults to the row's own `version` when `minimum_supported_version` is null

Helper: `makeAppVersion(array $overrides = [])` doing `AppVersion::create([...defaults, ...$overrides])`.

### Mobile (Jest)

- `config/__tests__/appVersion.test.ts` — `compareVersions`: older/newer/equal, missing segments
  treated as 0, pre-release/build-metadata ignored, garbage → `0.0.0`.
- `stores/__tests__/appUpdateStore.test.ts` (mock `expo-secure-store` + `@/api/appUpdate`):
  - stores the payload + sets `checkedThisSession` on a successful check
  - no-op on a second `check()` unless `check(true)`
  - a later failed poll keeps the last good `info`
  - `dismiss()` persists `latestVersion` and `hydrate()` re-reads it across a cold start

---

## 7. Per-release chore

1. Bump `expo.version` (and `android.versionCode` / `ios.buildNumber`) in `app.json`; build & submit.
2. Backend → **Superadmin → Settings → Mobile App Versions → New**: set `version` +
   `version_code` to match, pick the platform, paste the store URL + release notes, toggle **Active**
   (and **Force update** only for an urgent pull). Add a second row for the other platform.
   *(Config-only projects: bump `MOBILE_APP_LATEST_VERSION` in `.env` + `php artisan config:cache`.)*
3. Older devices see the banner on next launch / foreground; devices below `minimum_supported_version`
   (or any device when Force update is on and behind) get the blocking screen.

---

## 8. Source files (OguaPOS)

**Backend** (`C:\xampp\htdocs\Projects\POS`):
`database/migrations/2026_08_15_000000_create_app_versions_table.php`,
`app/Models/AppVersion.php`,
`app/Http/Controllers/Api/V1/AppUpdateController.php`,
`app/Filament/Superadmin/Resources/AppVersionResource.php` (+ `AppVersionResource/Pages/`),
`config/mobileapp.php`,
`routes/api.php` (the `app/update-check` line),
`tests/Feature/Api/V1/AppUpdateCheckTest.php`.

**Mobile** (this repo):
`src/config/appVersion.ts`, `src/api/appUpdate.ts`, `src/stores/appUpdateStore.ts`,
`src/hooks/useAppUpdate.ts`, `src/components/AppUpdateGate.tsx`, `src/components/AppVersionFooter.tsx`,
`src/app/_layout.tsx`, `src/app/(app)/settings.tsx`, `src/app/(app)/more.tsx`,
`src/config/__tests__/appVersion.test.ts`, `src/stores/__tests__/appUpdateStore.test.ts`.

**Reference implementation** (`C:\xampp\htdocs\Projects\oguaschoolv2`): `app/Models/AppVersion.php`,
`database/migrations/2024_01_22_create_app_versions_table.php`,
`app/Http/Controllers/Api/V2/AppVersionController.php`,
`app/Filament/Superadmin/Resources/AppVersions/` (split `Schemas/` + `Tables/` layout),
`app/Policies/AppVersionPolicy.php`, `routes/api_v2.php`.
