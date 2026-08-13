import React, { useState } from 'react';
import {

  FlatList,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as Haptics from 'expo-haptics';
import { useColors } from '@/hooks/useColors';
import { useCart } from '@/context/CartContext';
import { calcOrderTotals, isValidPromoCode } from '@/constants/pricing';
import { SwipeableCartItem } from '@/components/SwipeableCartItem';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const WEB_BOTTOM = Platform.OS === 'web' ? 34 + 84 : 0;

export default function CartScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { items, removeItem, updateQuantity, subtotal } = useCart();
  const [promoCode, setPromoCode] = useState('');
  const [appliedPromo, setAppliedPromo] = useState<string | null>(null);
  const [promoError, setPromoError] = useState('');

  const { discount, deliveryFee, total } = calcOrderTotals(subtotal, appliedPromo, items.length);

  const applyPromo = () => {
    const code = promoCode.trim().toUpperCase();
    if (isValidPromoCode(code)) {
      setAppliedPromo(code);
      setPromoError('');
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    } else {
      setPromoError('Invalid promo code');
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
    }
  };

  const removePromo = () => {
    setAppliedPromo(null);
    setPromoCode('');
    setPromoError('');
  };

  if (items.length === 0) {
    return (
      <View
        style={[
          styles.emptyContainer,
          {
            backgroundColor: colors.background,
            paddingTop: insets.top + WEB_TOP,
          },
        ]}
      >
        <View style={[styles.emptyIcon, { backgroundColor: colors.secondary, borderRadius: 60 }]}>
          <Ionicons name="cart-outline" size={64} color={colors.primary} />
        </View>
        <Text style={[styles.emptyTitle, { color: colors.foreground }]}>Your cart is empty</Text>
        <Text style={[styles.emptySubtitle, { color: colors.mutedForeground }]}>
          Add some fresh groceries to get started
        </Text>
        <Pressable
          style={[styles.shopBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
          onPress={() => router.push('/')}
        >
          <Text style={[styles.shopBtnText, { color: colors.primaryForeground }]}>
            Browse Products
          </Text>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      <FlatList
        data={items}
        keyExtractor={(item) => String(item.productId)}
        contentContainerStyle={{
          padding: 16,
          paddingTop: insets.top + WEB_TOP + 8,
        }}
        showsVerticalScrollIndicator={false}
        ListHeaderComponent={
          <Text style={[styles.pageTitle, { color: colors.foreground }]}>
            My Cart ({items.length})
          </Text>
        }
        renderItem={({ item }) => (
          <SwipeableCartItem
            item={item}
            onRemove={() => removeItem(item.productId)}
            onUpdateQuantity={(qty) => updateQuantity(item.productId, qty)}
          />
        )}
        ListFooterComponent={
          <View style={{ gap: 12, marginTop: 8 }}>
            {/* Promo code */}
            <View
              style={[
                styles.promoContainer,
                {
                  backgroundColor: colors.card,
                  borderColor: colors.border,
                  borderRadius: colors.radius,
                },
              ]}
            >
              {appliedPromo ? (
                <View style={styles.appliedPromo}>
                  <View style={[styles.promoBadge, { backgroundColor: colors.secondary }]}>
                    <Ionicons name="pricetag" size={14} color={colors.primary} />
                    <Text style={[styles.appliedCode, { color: colors.primary }]}>
                      {appliedPromo} applied
                    </Text>
                  </View>
                  <Pressable onPress={removePromo}>
                    <Ionicons name="close-circle" size={20} color={colors.mutedForeground} />
                  </Pressable>
                </View>
              ) : (
                <View style={styles.promoInputRow}>
                  <TextInput
                    style={[styles.promoInput, { color: colors.foreground }]}
                    placeholder="Promo code (e.g. FRESH10)"
                    placeholderTextColor={colors.mutedForeground}
                    value={promoCode}
                    onChangeText={(t) => { setPromoCode(t); setPromoError(''); }}
                    autoCapitalize="characters"
                    returnKeyType="done"
                    onSubmitEditing={applyPromo}
                  />
                  <Pressable
                    style={[styles.applyBtn, { backgroundColor: colors.primary, borderRadius: colors.radius - 4 }]}
                    onPress={applyPromo}
                  >
                    <Text style={{ color: '#FFF', fontFamily: 'Inter_600SemiBold', fontSize: 13 }}>Apply</Text>
                  </Pressable>
                </View>
              )}
              {promoError ? (
                <Text style={[styles.promoError, { color: colors.destructive }]}>{promoError}</Text>
              ) : null}
            </View>

            {/* Price breakdown */}
            <View
              style={[
                styles.summary,
                {
                  backgroundColor: colors.card,
                  borderColor: colors.border,
                  borderRadius: colors.radius,
                },
              ]}
            >
              <Text style={[styles.summaryTitle, { color: colors.foreground }]}>Order Summary</Text>
              <View style={styles.summaryRow}>
                <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Subtotal</Text>
                <Text style={[styles.summaryValue, { color: colors.foreground }]}>${subtotal.toFixed(2)}</Text>
              </View>
              {discount > 0 && (
                <View style={styles.summaryRow}>
                  <Text style={[styles.summaryLabel, { color: colors.primary }]}>Discount ({appliedPromo})</Text>
                  <Text style={[styles.summaryValue, { color: colors.primary }]}>-${discount.toFixed(2)}</Text>
                </View>
              )}
              <View style={styles.summaryRow}>
                <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Delivery fee</Text>
                <Text style={[styles.summaryValue, { color: colors.foreground }]}>${deliveryFee.toFixed(2)}</Text>
              </View>
              <View style={[styles.divider, { backgroundColor: colors.border }]} />
              <View style={styles.summaryRow}>
                <Text style={[styles.totalLabel, { color: colors.foreground }]}>Total</Text>
                <Text style={[styles.totalValue, { color: colors.primary }]}>${total.toFixed(2)}</Text>
              </View>
            </View>
          </View>
        }
      />

      {/* Checkout button */}
      <View
        style={[
          styles.checkoutBar,
          {
            backgroundColor: colors.background,
            borderTopColor: colors.border,
            paddingBottom: insets.bottom + WEB_BOTTOM + 8,
          },
        ]}
      >
        <Pressable
          style={({ pressed }) => [
            styles.checkoutBtn,
            {
              backgroundColor: colors.primary,
              borderRadius: colors.radius,
              opacity: pressed ? 0.9 : 1,
            },
          ]}
          onPress={() => router.push('/checkout')}
        >
          <Text style={[styles.checkoutText, { color: colors.primaryForeground }]}>
            Proceed to Checkout · ${total.toFixed(2)}
          </Text>
          <Ionicons name="arrow-forward" size={18} color={colors.primaryForeground} />
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  pageTitle: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
    marginBottom: 16,
  },
  emptyContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    padding: 40,
  },
  emptyIcon: {
    width: 120,
    height: 120,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 8,
  },
  emptyTitle: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
  },
  emptySubtitle: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
  },
  shopBtn: {
    marginTop: 8,
    paddingHorizontal: 32,
    paddingVertical: 14,
  },
  shopBtnText: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
  },
  promoContainer: {
    borderWidth: 1,
    padding: 12,
  },
  promoInputRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  promoInput: {
    flex: 1,
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    padding: 0,
  },
  applyBtn: {
    paddingHorizontal: 16,
    paddingVertical: 8,
  },
  appliedPromo: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  promoBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 20,
  },
  appliedCode: {
    fontSize: 13,
    fontFamily: 'Inter_600SemiBold',
  },
  promoError: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
    marginTop: 6,
  },
  summary: {
    padding: 16,
    borderWidth: 1,
    gap: 10,
  },
  summaryTitle: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
    marginBottom: 4,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  summaryLabel: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
  },
  summaryValue: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
  },
  divider: {
    height: 1,
    marginVertical: 4,
  },
  totalLabel: {
    fontSize: 16,
    fontFamily: 'Inter_700Bold',
  },
  totalValue: {
    fontSize: 18,
    fontFamily: 'Inter_700Bold',
  },
  checkoutBar: {
    padding: 16,
    borderTopWidth: 1,
  },
  checkoutBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 16,
  },
  checkoutText: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
});
