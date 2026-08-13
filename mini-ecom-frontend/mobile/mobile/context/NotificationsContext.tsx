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
import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import {
  useListNotifications,
  useRegisterDevice,
  useUnregisterDevice,
} from '@workspace/api-client-react';
import type { DeviceTokenInputPlatform } from '@workspace/api-client-react';
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
  const { mutateAsync: registerDevice } = useRegisterDevice();
  const { mutateAsync: unregisterDevice } = useUnregisterDevice();

  const seenIdsRef = useRef<Set<number> | null>(null);
  const pushTokenRef = useRef<string | null>(null);
  const wasAuthedRef = useRef(false);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: notifications } = useListNotifications({
    query: { refetchInterval: POLL_INTERVAL_MS, enabled: !!user } as any,
  });

  // Attempt remote-push registration once per sign-in. Best-effort: Expo Go
  // has no EAS project id in dev, and remote push isn't available on Expo Go
  // SDK 53+ Android anyway — the poller below is the working path in dev, so
  // any failure here is swallowed and we carry on silently.
  useEffect(() => {
    if (!user || Platform.OS === 'web') return;

    let cancelled = false;

    (async () => {
      try {
        await Notifications.requestPermissionsAsync();

        const projectId = Constants.expoConfig?.extra?.eas?.projectId;
        if (!projectId) return;

        const { data } = await Notifications.getExpoPushTokenAsync({ projectId });
        if (cancelled) return;

        pushTokenRef.current = data;
        await registerDevice({
          data: { token: data, platform: Platform.OS as DeviceTokenInputPlatform },
        });
      } catch {
        // No EAS project id / no remote push support in Expo Go — ignore.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [user, registerDevice]);

  // Best-effort device unregistration on logout (only if we actually
  // registered a token for this session).
  useEffect(() => {
    if (user) {
      wasAuthedRef.current = true;
      return;
    }

    if (wasAuthedRef.current && pushTokenRef.current) {
      const token = pushTokenRef.current;
      pushTokenRef.current = null;
      unregisterDevice({
        data: { token, platform: Platform.OS as DeviceTokenInputPlatform },
      }).catch(() => {
        // best-effort — nothing actionable if this fails
      });
    }

    wasAuthedRef.current = false;
    // Reseed on the next sign-in rather than firing a burst of whatever the
    // next user's history happens to contain.
    seenIdsRef.current = null;
  }, [user, unregisterDevice]);

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
