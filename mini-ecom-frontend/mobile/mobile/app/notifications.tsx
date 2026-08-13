import React from 'react';
import {
  FlatList,
  Platform,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as Haptics from 'expo-haptics';
import {
  useListNotifications,
  useMarkNotificationRead,
} from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useAuth } from '@/context/AuthContext';
import { useQueryClient } from '@tanstack/react-query';
import { getListNotificationsQueryKey } from '@workspace/api-client-react';
import type { Notification } from '@workspace/api-client-react';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

const TYPE_ICONS: Record<string, { icon: string; color: string }> = {
  order: { icon: 'receipt-outline', color: '#3B82F6' },
  promo: { icon: 'pricetag-outline', color: '#F97316' },
  system: { icon: 'information-circle-outline', color: '#8B5CF6' },
};

function NotificationItem({ notif }: { notif: Notification }) {
  const colors = useColors();
  const queryClient = useQueryClient();
  const markRead = useMarkNotificationRead();
  const { icon, color } = TYPE_ICONS[notif.type] ?? TYPE_ICONS['system']!;

  const handlePress = async () => {
    if (!notif.isRead) {
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
      await markRead.mutateAsync({ id: notif.id });
      queryClient.invalidateQueries({ queryKey: getListNotificationsQueryKey() });
    }
  };

  return (
    <Pressable
      style={({ pressed }) => [
        styles.notifItem,
        {
          backgroundColor: notif.isRead ? colors.card : colors.secondary,
          borderColor: notif.isRead ? colors.border : colors.primary + '40',
          borderRadius: colors.radius,
          opacity: pressed ? 0.9 : 1,
        },
      ]}
      onPress={handlePress}
    >
      <View style={[styles.notifIcon, { backgroundColor: color + '15', borderRadius: 10 }]}>
        <Ionicons name={icon as any} size={20} color={color} />
      </View>
      <View style={{ flex: 1, gap: 3 }}>
        <View style={styles.notifHeader}>
          <Text style={[styles.notifTitle, { color: colors.foreground }]} numberOfLines={1}>
            {notif.title}
          </Text>
          {!notif.isRead && (
            <View style={[styles.unreadDot, { backgroundColor: colors.primary }]} />
          )}
        </View>
        <Text style={[styles.notifBody, { color: colors.mutedForeground }]} numberOfLines={2}>
          {notif.body}
        </Text>
        <Text style={[styles.notifTime, { color: colors.mutedForeground }]}>
          {new Date(notif.createdAt).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
          })}
        </Text>
      </View>
    </Pressable>
  );
}

export default function NotificationsScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { user } = useAuth();

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: notifications, isLoading, refetch } = useListNotifications({
    query: { enabled: !!user } as any,
  });

  const unreadCount = (notifications ?? []).filter((n) => !n.isRead).length;

  if (!user) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Ionicons name="notifications-outline" size={48} color={colors.mutedForeground} />
        <Text style={[styles.gateTitle, { color: colors.foreground }]}>Sign in to view notifications</Text>
        <Pressable
          style={[styles.signInBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
          onPress={() => router.push('/(auth)/login')}
        >
          <Text style={[styles.signInText, { color: '#FFF' }]}>Sign In</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      <View
        style={[
          styles.header,
          {
            paddingTop: insets.top + WEB_TOP + 8,
            backgroundColor: colors.background,
            borderBottomColor: colors.border,
          },
        ]}
      >
        <Pressable onPress={() => router.back()} hitSlop={8}>
          <Ionicons name="chevron-back" size={24} color={colors.foreground} />
        </Pressable>
        <Text style={[styles.headerTitle, { color: colors.foreground }]}>
          Notifications
          {unreadCount > 0 && (
            <Text style={[styles.unreadBadge, { color: colors.primary }]}> ({unreadCount})</Text>
          )}
        </Text>
        <View style={{ width: 32 }} />
      </View>

      <FlatList
        data={notifications ?? []}
        keyExtractor={(n) => String(n.id)}
        contentContainerStyle={[
          styles.listContent,
          { paddingBottom: insets.bottom + 40 },
        ]}
        showsVerticalScrollIndicator={false}
        refreshControl={
          <RefreshControl refreshing={isLoading} onRefresh={refetch} tintColor={colors.primary} />
        }
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Ionicons name="notifications-off-outline" size={56} color={colors.mutedForeground} />
              <Text style={[styles.emptyTitle, { color: colors.foreground }]}>No notifications</Text>
              <Text style={[styles.emptySubtitle, { color: colors.mutedForeground }]}>
                You're all caught up!
              </Text>
            </View>
          ) : null
        }
        renderItem={({ item }) => <NotificationItem notif={item} />}
        ItemSeparatorComponent={() => <View style={{ height: 8 }} />}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 40 },
  gateTitle: { fontSize: 18, fontFamily: 'Inter_600SemiBold', textAlign: 'center' },
  signInBtn: { paddingHorizontal: 32, paddingVertical: 14 },
  signInText: { fontSize: 15, fontFamily: 'Inter_600SemiBold' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    gap: 12,
  },
  headerTitle: { flex: 1, fontSize: 18, fontFamily: 'Inter_700Bold' },
  unreadBadge: { fontFamily: 'Inter_400Regular' },
  listContent: { padding: 16 },
  notifItem: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    padding: 14,
    borderWidth: 1,
  },
  notifIcon: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    flexShrink: 0,
  },
  notifHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  notifTitle: {
    flex: 1,
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    flexShrink: 0,
  },
  notifBody: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    lineHeight: 18,
  },
  notifTime: {
    fontSize: 11,
    fontFamily: 'Inter_400Regular',
  },
  empty: { alignItems: 'center', paddingTop: 80, gap: 10 },
  emptyTitle: { fontSize: 20, fontFamily: 'Inter_600SemiBold' },
  emptySubtitle: { fontSize: 14, fontFamily: 'Inter_400Regular', textAlign: 'center' },
});
