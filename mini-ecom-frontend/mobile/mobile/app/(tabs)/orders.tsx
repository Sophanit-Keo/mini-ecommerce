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
import { useListOrders } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useAuth } from '@/context/AuthContext';
import type { Order } from '@workspace/api-client-react';
import { STATUS_COLOR, STATUS_LABEL } from '@/constants/orderStatus';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const WEB_BOTTOM = Platform.OS === 'web' ? 34 + 84 : 0;

function OrderCard({ order }: { order: Order }) {
  const colors = useColors();
  const statusColor = STATUS_COLOR[order.status] ?? colors.mutedForeground;

  return (
    <Pressable
      style={({ pressed }) => [
        styles.orderCard,
        {
          backgroundColor: colors.card,
          borderColor: colors.border,
          borderRadius: colors.radius,
          opacity: pressed ? 0.95 : 1,
        },
      ]}
      onPress={() => router.push(`/order/${order.id}`)}
    >
      <View style={styles.cardTop}>
        <View>
          <Text style={[styles.orderId, { color: colors.mutedForeground }]}>
            Order #{order.id}
          </Text>
          <Text style={[styles.orderDate, { color: colors.foreground }]}>
            {new Date(order.createdAt).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
            })}
          </Text>
        </View>
        <View style={[styles.statusBadge, { backgroundColor: statusColor + '20', borderRadius: 20 }]}>
          <View style={[styles.statusDot, { backgroundColor: statusColor }]} />
          <Text style={[styles.statusText, { color: statusColor }]}>
            {STATUS_LABEL[order.status] ?? order.status}
          </Text>
        </View>
      </View>

      <View style={[styles.divider, { backgroundColor: colors.border }]} />

      <View style={styles.cardBottom}>
        <View>
          <Text style={[styles.itemCount, { color: colors.mutedForeground }]}>
            {order.items.length} {order.items.length === 1 ? 'item' : 'items'}
          </Text>
          <Text style={[styles.itemPreview, { color: colors.foreground }]} numberOfLines={1}>
            {order.items.map((i) => i.name).join(', ')}
          </Text>
        </View>
        <View style={styles.totalCol}>
          <Text style={[styles.totalAmount, { color: colors.primary }]}>
            ${order.total.toFixed(2)}
          </Text>
          <Ionicons name="chevron-forward" size={16} color={colors.mutedForeground} />
        </View>
      </View>
    </Pressable>
  );
}

export default function OrdersScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { user } = useAuth();

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: orders, isLoading, refetch } = useListOrders({
    query: { enabled: !!user } as any,
  });

  if (!user) {
    return (
      <View
        style={[
          styles.authGate,
          {
            backgroundColor: colors.background,
            paddingTop: insets.top + WEB_TOP,
          },
        ]}
      >
        <Ionicons name="receipt-outline" size={64} color={colors.mutedForeground} />
        <Text style={[styles.gateTitle, { color: colors.foreground }]}>Track your orders</Text>
        <Text style={[styles.gateSubtitle, { color: colors.mutedForeground }]}>
          Sign in to view your order history
        </Text>
        <Pressable
          style={[styles.signInBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
          onPress={() => router.push('/(auth)/login')}
        >
          <Text style={[styles.signInText, { color: colors.primaryForeground }]}>Sign In</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <FlatList
      data={orders ?? []}
      keyExtractor={(o) => String(o.id)}
      contentContainerStyle={{
        padding: 16,
        paddingTop: insets.top + WEB_TOP + 8,
        paddingBottom: insets.bottom + WEB_BOTTOM + 24,
      }}
      style={{ backgroundColor: colors.background }}
      showsVerticalScrollIndicator={false}
      refreshControl={
        <RefreshControl refreshing={isLoading} onRefresh={refetch} tintColor={colors.primary} />
      }
      ListHeaderComponent={
        <Text style={[styles.pageTitle, { color: colors.foreground }]}>My Orders</Text>
      }
      ListEmptyComponent={
        !isLoading ? (
          <View style={styles.emptyState}>
            <Ionicons name="receipt-outline" size={56} color={colors.mutedForeground} />
            <Text style={[styles.emptyTitle, { color: colors.foreground }]}>No orders yet</Text>
            <Text style={[styles.emptySubtitle, { color: colors.mutedForeground }]}>
              Your orders will appear here after checkout
            </Text>
            <Pressable
              style={[styles.signInBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
              onPress={() => router.push('/')}
            >
              <Text style={[styles.signInText, { color: colors.primaryForeground }]}>Start Shopping</Text>
            </Pressable>
          </View>
        ) : null
      }
      renderItem={({ item }) => <OrderCard order={item} />}
      ItemSeparatorComponent={() => <View style={{ height: 10 }} />}
    />
  );
}

const styles = StyleSheet.create({
  pageTitle: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
    marginBottom: 16,
  },
  orderCard: {
    borderWidth: 1,
    overflow: 'hidden',
  },
  cardTop: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    padding: 14,
  },
  orderId: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  orderDate: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
    marginTop: 2,
  },
  statusBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  statusDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  statusText: {
    fontSize: 12,
    fontFamily: 'Inter_600SemiBold',
  },
  divider: { height: 1 },
  cardBottom: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 14,
  },
  itemCount: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  itemPreview: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
    maxWidth: 220,
    marginTop: 2,
  },
  totalCol: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  totalAmount: {
    fontSize: 16,
    fontFamily: 'Inter_700Bold',
  },
  authGate: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    padding: 40,
  },
  gateTitle: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
  },
  gateSubtitle: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
  },
  signInBtn: {
    marginTop: 8,
    paddingHorizontal: 32,
    paddingVertical: 14,
  },
  signInText: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
  },
  emptyState: {
    alignItems: 'center',
    paddingTop: 60,
    gap: 10,
  },
  emptyTitle: {
    fontSize: 20,
    fontFamily: 'Inter_600SemiBold',
  },
  emptySubtitle: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
  },
});
