/**
 * Console logging for debugging API and tracking issues. Visible in the Metro
 * terminal for dev builds, and in `adb logcat -s ReactNativeJS` / Xcode for
 * release builds. Full request/response dumps are dev-only (they contain
 * customer and money data); errors are always logged so a field problem can
 * be diagnosed from a release build.
 */

const VERBOSE = __DEV__ || process.env.EXPO_PUBLIC_API_DEBUG === 'true';
const MAX_LENGTH = 4_000;
const SECRET_KEYS = /^(password|password_confirmation|current_password|pin|token|access_token|authorization)$/i;

export function debugLog(tag: string, message: string, data?: unknown): void {
  if (!VERBOSE) return;
  console.log(`[${tag}] ${message}`, ...(data === undefined ? [] : [format(data)]));
}

/** console.log, not warn/error: those pop a LogBox toast in dev for every
 * routine 422, and release builds still send console.log to the device log. */
export function errorLog(tag: string, message: string, data?: unknown): void {
  console.log(`[${tag}] ERROR ${message}`,...(data === undefined ? [] : [format(data)]));
}

/** Redacts secrets and truncates so a huge payload can't flood the log. */
function format(data: unknown): string {
  let text: string;
  try {
    text = JSON.stringify(
      typeof data === 'string' ? tryParse(data) : data,
      (key, value: unknown) => (SECRET_KEYS.test(key) ? '***' : value),
      2,
    );
  } catch {
    text = String(data);
  }

  return text.length > MAX_LENGTH ? `${text.slice(0, MAX_LENGTH)}… (${text.length} chars)` : text;
}

function tryParse(value: string): unknown {
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}
