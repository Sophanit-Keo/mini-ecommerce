/**
 * App-wide notifications: local-notification presentation config, a 15s
 * poller against the server's notification feed, and (best-effort) remote
 * push device registration.
 *
 * This replaces the per-screen logic that used to live in `app/order/[id].tsx`
 * — that screen only fired notifications for order-status changes, and only
 * while it happened to be mounted. Server notifications (order updates,
 * promos, anything else `useListNotifications` returns) now surface no
 * matter which screen the user is on.
 */
import React, {
  createContext,
  useContext,
  useEffect,
  useRef,
} from 'react';
import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { useListNotifications } from '@workspace/api-client-react';
import { useAuth } from '@/context/AuthContext';

// Configure foreground notification behaviour. This is a module-scope side
// effect, but this module is only ever imported by the root layout (which is
// always loaded), so — unlike the old copy that lived at the top of
// app/order/[id].tsx — it's guaranteed to run regardless of which screen is
// mounted.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

const POLL_INTERVAL_MS = 15_000;

// The provider works entirely off side effects, so it exposes no value today.
// Typed as an empty object rather than an empty interface, which eslint flags
// for accepting any non-nullish value.
type NotificationsContextType = Record<string, never>;

const NotificationsContext = createContext<NotificationsContextType | null>(null);

export function NotificationsProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const seenIdsRef = useRef<Set<string> | null>(null);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: notifications } = useListNotifications({
    query: { refetchInterval: POLL_INTERVAL_MS, enabled: !!user } as any,
  });

  // The backend currently exposes notification polling, not device-token registration.
  // Reset the local deduplication set on sign-out so a subsequent account does not inherit
  // the previous account’s notification history.
  useEffect(() => {
    if (!user) seenIdsRef.current = null;
  }, [user]);

  // Fire a local notification for every id we haven't already seen. The
  // first successful response after (re)authenticating only seeds the set —
  // it must not fire a burst of every historical notification on load.
  useEffect(() => {
    if (!notifications) return;

    if (seenIdsRef.current === null) {
      seenIdsRef.current = new Set(notifications.map((n) => n.id));
      return;
    }

    if (Platform.OS === 'web') return;

    for (const n of notifications) {
      if (!seenIdsRef.current.has(n.id)) {
        seenIdsRef.current.add(n.id);
        Notifications.scheduleNotificationAsync({
          content: { title: n.title, body: n.body },
          trigger: null,
        }).catch(() => {
          // best-effort
        });
      }
    }
  }, [notifications]);

  return (
    <NotificationsContext.Provider value={{}}>{children}</NotificationsContext.Provider>
  );
}

export function useNotifications() {
  const ctx = useContext(NotificationsContext);
  if (!ctx) throw new Error('useNotifications must be used inside NotificationsProvider');
  return ctx;
}
