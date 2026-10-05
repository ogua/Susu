import * as Crypto from 'expo-crypto';
import { useCallback, useState } from 'react';

/**
 * A `client_reference` for one online financial submission. The backend
 * returns the already-posted entry when it sees the same reference twice, so
 * a retry after a timeout can't double-post. Keep the key for every retry of
 * the same attempt; call `rotate()` only after the server confirms success
 * (or when the user changes what they're submitting).
 */
export function useIdempotencyKey(): { key: string; rotate: () => void } {
  const [key, setKey] = useState(() => Crypto.randomUUID());
  const rotate = useCallback(() => setKey(Crypto.randomUUID()), []);

  return { key, rotate };
}
