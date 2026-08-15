import React from 'react';
import {
  Alert,
  Image,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import {
  useCancelOrder,
  useGetOrder,
} from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useCart } from '@/context/CartContext';
import { OrderTimeline } from '@/components/OrderTimeline';
import { DeliveryMap } from '@/components/DeliveryMap';
import * as Haptics from 'expo-haptics';
import { STATUS_COLOR, TERMINAL_STATUSES, CANCELLABLE_STATUSES, isTerminal } from '@/constants/orderStatus';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

export default function OrderTrackingScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { addItem } = useCart();
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: order, isLoading, isError } = useGetOrder(id, {
    query: {
      refetchInterval: (query: any) => {
        const status = query.state.data?.status;
        return status && TERMINAL_STATUSES.includes(status) ? false : 5000;
      },
    } as any,
  });

  const { mutate: cancelOrder, isPending: isCancelling } = useCancelOrder();

  const handleReorder = () => {
    if (!order) return;
    order.items.forEach((item) => {
      addItem({
        productId: item.productId,
        name: item.name,
        price: item.price,
        unit: item.unit,
        image: item.image,
        quantity: item.quantity,
      });
    });
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    router.push('/(tabs)/cart');
  };

  const handleCancel = () => {
    Alert.alert(
      'Cancel Order',
      'Are you sure you want to cancel this order?',
      [
        { text: 'Keep order', style: 'cancel' },
        {
          text: 'Cancel order',
          style: 'destructive',
          onPress: () => {
            Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning);
            cancelOrder(
              { id },
              {
                onError: () => {
                  Alert.alert('Error', 'Could not cancel the order. Please try again.');
                },
              },
            );
          },
        },
      ],
    );
  };

  if (isLoading) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Ionicons name="bicycle-outline" size={48} color={colors.primary} />
        <Text style={[{ color: colors.mutedForeground, fontSize: 14, fontFamily: 'Inter_400Regular' }]}>
          Loading order...
        </Text>
      </View>
    );
  }

  if (isError || !order) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Text style={[{ color: colors.foreground, fontSize: 16 }]}>Order not found</Text>
        <Pressable onPress={() => router.back()}>
          <Text style={[{ color: colors.primary }]}>Go back</Text>
        </Pressable>
      </View>
    );
  }

  const statusColor = STATUS_COLOR[order.status] ?? colors.primary;
  const canCancel = CANCELLABLE_STATUSES.includes(order.status);

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {/* Header */}
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
          Order #{order.id}
        </Text>
        {isTerminal(order.status) ? (
          <Pressable
            style={[styles.reorderBtn, { backgroundColor: colors.primary, borderRadius: colors.radius - 4 }]}
            onPress={handleReorder}
          >
            <Text style={[styles.reorderText, { color: '#FFF' }]}>Reorder</Text>
          </Pressable>
        ) : (
          <View style={{ width: 72 }} />
        )}
      </View>

      <ScrollView
        contentContainerStyle={[
          styles.scrollContent,
          { paddingBottom: insets.bottom + 40 },
        ]}
        showsVerticalScrollIndicator={false}
      >
        {/* Status banner */}
        <View
          style={[
            styles.statusBanner,
            { backgroundColor: statusColor + '15', borderRadius: colors.radius },
          ]}
        >
          <View style={[styles.statusDot, { backgroundColor: statusColor }]} />
          <Text style={[styles.statusText, { color: statusColor }]}>
            {order.status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
          </Text>
          <Text style={[styles.statusDate, { color: colors.mutedForeground }]}>
            {new Date(order.createdAt).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
            })}
          </Text>
        </View>

        {/* Delivery Map */}
        <DeliveryMap status={order.status} address={order.address} />


        {/* View receipt (once the seller has approved and issued an invoice) */}
        {order.invoiceNumber != null && (
          <Pressable
            style={[
              styles.receiptBtn,
              { borderColor: colors.border, borderRadius: colors.radius },
            ]}
            onPress={() => router.push(`/invoice/${order.id}`)}
          >
            <Ionicons name="receipt-outline" size={16} color={colors.foreground} />
            <Text style={[styles.receiptBtnText, { color: colors.foreground }]}>View Receipt</Text>
          </Pressable>
        )}

        {/* Cancel button (pending / confirmed only) */}
        {canCancel && (
          <Pressable
            style={[
              styles.cancelBtn,
              {
                borderColor: colors.destructive,
                borderRadius: colors.radius,
                opacity: isCancelling ? 0.5 : 1,
              },
            ]}
            onPress={handleCancel}
            disabled={isCancelling}
          >
            <Ionicons name="close-circle-outline" size={16} color={colors.destructive} />
            <Text style={[styles.cancelBtnText, { color: colors.destructive }]}>
              {isCancelling ? 'Cancelling…' : 'Cancel Order'}
            </Text>
          </Pressable>
        )}

        {/* Timeline */}
        <OrderTimeline timeline={order.timeline} currentStatus={order.status} />

        {/* Order items */}
        <View style={[styles.itemsCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <Text style={[styles.cardTitle, { color: colors.foreground }]}>
            Items ({order.items.length})
          </Text>
          {order.items.map((item) => (
            <View key={item.id} style={styles.orderItem}>
              <Image
                source={{ uri: item.image }}
                style={[styles.itemImage, { borderRadius: colors.radius - 4 }]}
                resizeMode="cover"
              />
              <View style={{ flex: 1 }}>
                <Text style={[styles.itemName, { color: colors.foreground }]} numberOfLines={2}>
                  {item.name}
                </Text>
                <Text style={[styles.itemUnit, { color: colors.mutedForeground }]}>{item.unit}</Text>
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                <Text style={[styles.itemQty, { color: colors.mutedForeground }]}>×{item.quantity}</Text>
                <Text style={[styles.itemPrice, { color: colors.foreground }]}>
                  ${(item.price * item.quantity).toFixed(2)}
                </Text>
              </View>
            </View>
          ))}
        </View>

        {/* Price summary */}
        <View style={[styles.itemsCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <Text style={[styles.cardTitle, { color: colors.foreground }]}>Payment</Text>
          <View style={styles.summaryRow}>
            <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Subtotal</Text>
            <Text style={[styles.summaryValue, { color: colors.foreground }]}>${order.subtotal.toFixed(2)}</Text>
          </View>
          {order.discount > 0 && (
            <View style={styles.summaryRow}>
              <Text style={[styles.summaryLabel, { color: colors.primary }]}>Discount</Text>
              <Text style={[styles.summaryValue, { color: colors.primary }]}>-${order.discount.toFixed(2)}</Text>
            </View>
          )}
          <View style={styles.summaryRow}>
            <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Delivery fee</Text>
            <Text style={[styles.summaryValue, { color: colors.foreground }]}>${order.deliveryFee.toFixed(2)}</Text>
          </View>
          <View style={[styles.divider, { backgroundColor: colors.border }]} />
          <View style={styles.summaryRow}>
            <Text style={[styles.totalLabel, { color: colors.foreground }]}>Total</Text>
            <Text style={[styles.totalValue, { color: colors.primary }]}>${order.total.toFixed(2)}</Text>
          </View>
          <View style={[styles.paymentMethodRow, { backgroundColor: colors.secondary, borderRadius: colors.radius - 4 }]}>
            <Ionicons name="card-outline" size={16} color={colors.primary} />
            <Text style={[styles.paymentMethodText, { color: colors.secondaryForeground }]}>
              {order.paymentMethod.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
            </Text>
          </View>
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    gap: 12,
  },
  headerTitle: { flex: 1, fontSize: 18, fontFamily: 'Inter_700Bold' },
  reorderBtn: { paddingHorizontal: 14, paddingVertical: 7 },
  reorderText: { fontSize: 13, fontFamily: 'Inter_600SemiBold' },
  scrollContent: { padding: 16, gap: 12 },
  statusBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    padding: 14,
  },
  statusDot: { width: 10, height: 10, borderRadius: 5 },
  statusText: { flex: 1, fontSize: 15, fontFamily: 'Inter_600SemiBold', textTransform: 'capitalize' },
  statusDate: { fontSize: 12, fontFamily: 'Inter_400Regular' },
  cancelBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 12,
    borderWidth: 1.5,
  },
  cancelBtnText: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
  confirmBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 12,
  },
  confirmBtnText: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
  receiptBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 12,
    borderWidth: 1.5,
  },
  receiptBtnText: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
  itemsCard: { padding: 14, borderWidth: 1, gap: 12 },
  cardTitle: { fontSize: 15, fontFamily: 'Inter_600SemiBold' },
  orderItem: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  itemImage: { width: 56, height: 56 },
  itemName: { fontSize: 13, fontFamily: 'Inter_500Medium', lineHeight: 18 },
  itemUnit: { fontSize: 11, fontFamily: 'Inter_400Regular', marginTop: 2 },
  itemQty: { fontSize: 12, fontFamily: 'Inter_400Regular' },
  itemPrice: { fontSize: 14, fontFamily: 'Inter_600SemiBold', marginTop: 2 },
  summaryRow: { flexDirection: 'row', justifyContent: 'space-between' },
  summaryLabel: { fontSize: 14, fontFamily: 'Inter_400Regular' },
  summaryValue: { fontSize: 14, fontFamily: 'Inter_500Medium' },
  divider: { height: 1, marginVertical: 4 },
  totalLabel: { fontSize: 16, fontFamily: 'Inter_700Bold' },
  totalValue: { fontSize: 18, fontFamily: 'Inter_700Bold' },
  paymentMethodRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 10,
    marginTop: 4,
  },
  paymentMethodText: { fontSize: 13, fontFamily: 'Inter_500Medium' },
});
