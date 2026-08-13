import React, { useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as Haptics from 'expo-haptics';
import {
  useListAddresses,
  useCreateAddress,
  useCreateOrder,
} from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useCart } from '@/context/CartContext';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/components/Toast';
import { calcOrderTotals, getDiscountRate, isValidPromoCode } from '@/constants/pricing';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

const STEPS = ['Address', 'Delivery', 'Payment', 'Review'];

const PAYMENT_METHODS = [
  { id: 'card', label: 'Credit / Debit Card', icon: 'card-outline' },
  { id: 'cash', label: 'Cash on Delivery', icon: 'cash-outline' },
  { id: 'apple_pay', label: 'Apple Pay', icon: 'logo-apple' },
];

const DELIVERY_SLOTS = [
  { id: 'asap', label: 'ASAP (45–60 min)', icon: 'flash-outline' },
  { id: 'afternoon', label: '12:00 – 15:00', icon: 'sunny-outline' },
  { id: 'evening', label: '17:00 – 20:00', icon: 'moon-outline' },
];

/** Simple UUID v4 for idempotency keys — no crypto dependency needed */
function generateUUID(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export default function CheckoutScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { items, subtotal, clearCart } = useCart();
  const { user } = useAuth();
  const toast = useToast();

  const [step, setStep] = useState(0);
  const [selectedAddress, setSelectedAddress] = useState<number | null>(null);
  const [selectedSlot, setSelectedSlot] = useState('asap');
  const [paymentMethod, setPaymentMethod] = useState('card');
  const [promoCode, setPromoCode] = useState('');
  const [notes, setNotes] = useState('');
  const [placing, setPlacing] = useState(false);
  const [placeError, setPlaceError] = useState('');
  const [newAddr, setNewAddr] = useState({ label: 'Home', street: '', city: '', state: '', zipCode: '' });
  const [showAddAddr, setShowAddAddr] = useState(false);

  // Stable idempotency key for this checkout session.
  // Reusing the same key on retry is intentional — the backend returns the
  // already-created order rather than creating a duplicate.
  const idempotencyKeyRef = useRef<string>(generateUUID());

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: addresses, refetch: refetchAddr } = useListAddresses({
    query: { enabled: !!user } as any,
  });
  const createAddress = useCreateAddress();
  // Pass the idempotency key at hook-init time so the backend can deduplicate
  // retries without creating a duplicate order.
  const createOrder = useCreateOrder({
    request: { headers: { 'X-Idempotency-Key': idempotencyKeyRef.current } },
  });

  const { discount, deliveryFee, total } = calcOrderTotals(subtotal, promoCode, items.length);

  if (!user) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Ionicons name="lock-closed-outline" size={48} color={colors.mutedForeground} />
        <Text style={[styles.gateTitle, { color: colors.foreground }]}>Sign in to checkout</Text>
        <Pressable
          style={[styles.primaryBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
          onPress={() => router.push('/(auth)/login')}
        >
          <Text style={[styles.primaryBtnText, { color: '#FFF' }]}>Sign In</Text>
        </Pressable>
      </View>
    );
  }

  const handlePlaceOrder = async () => {
    if (!selectedAddress) return;
    setPlacing(true);
    setPlaceError('');
    try {
      const result = await createOrder.mutateAsync({
        data: {
          addressId: selectedAddress,
          items: items.map((i) => ({ productId: i.productId, quantity: i.quantity })),
          promoCode: promoCode.toUpperCase() || null,
          paymentMethod,
          notes: notes || null,
          deliveryTime: null,
        },
      });
      // Cart cleared before navigation so it cannot be replayed accidentally
      clearCart();
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      router.replace({ pathname: '/checkout/success', params: { orderId: String(result.id) } });
    } catch (err) {
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      const msg =
        err instanceof Error && err.message
          ? err.message.replace(/^HTTP \d+ [^:]+:\s*/, '')
          : 'Something went wrong placing your order.';
      setPlaceError(msg);
      toast.show({
        type: 'error',
        message: 'Order failed — tap "Try again" or check your connection.',
        duration: 5000,
      });
    } finally {
      setPlacing(false);
    }
  };

  const handleAddAddress = async () => {
    if (!newAddr.street || !newAddr.city) return;
    try {
      const addr = await createAddress.mutateAsync({
        data: { ...newAddr, isDefault: (addresses?.length ?? 0) === 0 },
      });
      await refetchAddr();
      setSelectedAddress(addr.id);
      setShowAddAddr(false);
      setNewAddr({ label: 'Home', street: '', city: '', state: '', zipCode: '' });
    } catch {
      Alert.alert('Error', 'Could not save the address. Please check your details and try again.');
    }
  };

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
        <Pressable onPress={() => (step > 0 ? setStep(step - 1) : router.back())} hitSlop={8}>
          <Ionicons name="chevron-back" size={24} color={colors.foreground} />
        </Pressable>
        <Text style={[styles.headerTitle, { color: colors.foreground }]}>Checkout</Text>
        <Text style={[styles.stepCounter, { color: colors.mutedForeground }]}>
          {step + 1}/{STEPS.length}
        </Text>
      </View>

      {/* Step indicator */}
      <View style={styles.stepIndicator}>
        {STEPS.map((s, i) => (
          <React.Fragment key={s}>
            <View style={styles.stepItem}>
              <View
                style={[
                  styles.stepCircle,
                  {
                    backgroundColor: i <= step ? colors.primary : colors.muted,
                    borderColor: i === step ? colors.primary : 'transparent',
                  },
                ]}
              >
                {i < step ? (
                  <Ionicons name="checkmark" size={12} color="#FFF" />
                ) : (
                  <Text style={[styles.stepNum, { color: i <= step ? '#FFF' : colors.mutedForeground }]}>
                    {i + 1}
                  </Text>
                )}
              </View>
              <Text style={[styles.stepLabel, { color: i <= step ? colors.primary : colors.mutedForeground }]}>
                {s}
              </Text>
            </View>
            {i < STEPS.length - 1 && (
              <View style={[styles.stepLine, { backgroundColor: i < step ? colors.primary : colors.border }]} />
            )}
          </React.Fragment>
        ))}
      </View>

      <ScrollView
        contentContainerStyle={styles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {/* Step 0: Address */}
        {step === 0 && (
          <View style={styles.stepContent}>
            <Text style={[styles.stepTitle, { color: colors.foreground }]}>Select Delivery Address</Text>
            {(addresses ?? []).map((addr) => (
              <Pressable
                key={addr.id}
                style={[
                  styles.addrCard,
                  {
                    backgroundColor: selectedAddress === addr.id ? colors.secondary : colors.card,
                    borderColor: selectedAddress === addr.id ? colors.primary : colors.border,
                    borderRadius: colors.radius,
                  },
                ]}
                onPress={() => setSelectedAddress(addr.id)}
              >
                <View style={styles.addrRow}>
                  <View style={[styles.addrIcon, { backgroundColor: colors.primary + '15', borderRadius: 10 }]}>
                    <Ionicons name="location-outline" size={18} color={colors.primary} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={[styles.addrLabel, { color: colors.foreground }]}>{addr.label}</Text>
                    <Text style={[styles.addrStreet, { color: colors.mutedForeground }]}>
                      {addr.street}, {addr.city}, {addr.state} {addr.zipCode}
                    </Text>
                  </View>
                  {selectedAddress === addr.id && (
                    <Ionicons name="checkmark-circle" size={22} color={colors.primary} />
                  )}
                </View>
              </Pressable>
            ))}

            {showAddAddr ? (
              <View style={[styles.addAddrForm, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
                <Text style={[styles.formTitle, { color: colors.foreground }]}>New Address</Text>
                {(['street', 'city', 'state', 'zipCode'] as const).map((field) => (
                  <TextInput
                    key={field}
                    style={[styles.formInput, { backgroundColor: colors.input, borderColor: colors.border, borderRadius: colors.radius - 4, color: colors.foreground }]}
                    placeholder={field.charAt(0).toUpperCase() + field.slice(1)}
                    placeholderTextColor={colors.mutedForeground}
                    value={newAddr[field]}
                    onChangeText={(t) => setNewAddr((p) => ({ ...p, [field]: t }))}
                  />
                ))}
                <View style={{ flexDirection: 'row', gap: 8 }}>
                  <Pressable
                    style={[styles.formBtn, { borderColor: colors.border, borderRadius: colors.radius - 4, flex: 1 }]}
                    onPress={() => setShowAddAddr(false)}
                  >
                    <Text style={[{ color: colors.foreground, fontFamily: 'Inter_500Medium', fontSize: 14 }]}>Cancel</Text>
                  </Pressable>
                  <Pressable
                    style={[styles.formBtn, { backgroundColor: colors.primary, borderRadius: colors.radius - 4, flex: 2 }]}
                    onPress={handleAddAddress}
                  >
                    {createAddress.isPending ? (
                      <ActivityIndicator color="#FFF" />
                    ) : (
                      <Text style={[{ color: '#FFF', fontFamily: 'Inter_600SemiBold', fontSize: 14 }]}>Save Address</Text>
                    )}
                  </Pressable>
                </View>
              </View>
            ) : (
              <Pressable
                style={[styles.addAddrBtn, { borderColor: colors.primary, borderRadius: colors.radius }]}
                onPress={() => setShowAddAddr(true)}
              >
                <Ionicons name="add-circle-outline" size={20} color={colors.primary} />
                <Text style={[styles.addAddrText, { color: colors.primary }]}>Add new address</Text>
              </Pressable>
            )}
          </View>
        )}

        {/* Step 1: Delivery time */}
        {step === 1 && (
          <View style={styles.stepContent}>
            <Text style={[styles.stepTitle, { color: colors.foreground }]}>Choose Delivery Time</Text>
            {DELIVERY_SLOTS.map((slot) => (
              <Pressable
                key={slot.id}
                style={[
                  styles.slotCard,
                  {
                    backgroundColor: selectedSlot === slot.id ? colors.secondary : colors.card,
                    borderColor: selectedSlot === slot.id ? colors.primary : colors.border,
                    borderRadius: colors.radius,
                  },
                ]}
                onPress={() => setSelectedSlot(slot.id)}
              >
                <Ionicons name={slot.icon as any} size={22} color={selectedSlot === slot.id ? colors.primary : colors.mutedForeground} />
                <Text style={[styles.slotLabel, { color: selectedSlot === slot.id ? colors.primary : colors.foreground, fontFamily: selectedSlot === slot.id ? 'Inter_600SemiBold' : 'Inter_400Regular' }]}>
                  {slot.label}
                </Text>
                {selectedSlot === slot.id && <Ionicons name="checkmark-circle" size={20} color={colors.primary} />}
              </Pressable>
            ))}
          </View>
        )}

        {/* Step 2: Payment */}
        {step === 2 && (
          <View style={styles.stepContent}>
            <Text style={[styles.stepTitle, { color: colors.foreground }]}>Payment Method</Text>
            {PAYMENT_METHODS.map((pm) => (
              <Pressable
                key={pm.id}
                style={[
                  styles.slotCard,
                  {
                    backgroundColor: paymentMethod === pm.id ? colors.secondary : colors.card,
                    borderColor: paymentMethod === pm.id ? colors.primary : colors.border,
                    borderRadius: colors.radius,
                  },
                ]}
                onPress={() => setPaymentMethod(pm.id)}
              >
                <Ionicons name={pm.icon as any} size={22} color={paymentMethod === pm.id ? colors.primary : colors.mutedForeground} />
                <Text style={[styles.slotLabel, { color: paymentMethod === pm.id ? colors.primary : colors.foreground, fontFamily: paymentMethod === pm.id ? 'Inter_600SemiBold' : 'Inter_400Regular' }]}>
                  {pm.label}
                </Text>
                {paymentMethod === pm.id && <Ionicons name="checkmark-circle" size={20} color={colors.primary} />}
              </Pressable>
            ))}
          </View>
        )}

        {/* Step 3: Review */}
        {step === 3 && (
          <View style={styles.stepContent}>
            <Text style={[styles.stepTitle, { color: colors.foreground }]}>Review Order</Text>
            <View style={[styles.reviewCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
              <Text style={[styles.reviewSectionTitle, { color: colors.mutedForeground }]}>Items ({items.length})</Text>
              {items.map((item) => (
                <View key={item.productId} style={styles.reviewItem}>
                  <Text style={[styles.reviewItemName, { color: colors.foreground }]} numberOfLines={1}>{item.name}</Text>
                  <Text style={[styles.reviewItemPrice, { color: colors.mutedForeground }]}>×{item.quantity} · ${(item.price * item.quantity).toFixed(2)}</Text>
                </View>
              ))}
            </View>

            {/* Promo code */}
            <View style={[styles.reviewCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
              <Text style={[styles.reviewSectionTitle, { color: colors.mutedForeground }]}>Promo Code</Text>
              <View style={styles.promoRow}>
                <TextInput
                  style={[styles.promoInput, { color: colors.foreground, borderColor: colors.border, borderRadius: colors.radius - 4, backgroundColor: colors.input }]}
                  placeholder="Enter promo code"
                  placeholderTextColor={colors.mutedForeground}
                  value={promoCode}
                  onChangeText={setPromoCode}
                  autoCapitalize="characters"
                />
              </View>
              {isValidPromoCode(promoCode) && (
                <Text style={[{ color: colors.primary, fontSize: 12, fontFamily: 'Inter_500Medium', marginTop: 4 }]}>
                  {(getDiscountRate(promoCode) * 100).toFixed(0)}% discount applied!
                </Text>
              )}
            </View>

            {/* Notes */}
            <View style={[styles.reviewCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
              <Text style={[styles.reviewSectionTitle, { color: colors.mutedForeground }]}>Delivery Notes</Text>
              <TextInput
                style={[styles.notesInput, { color: colors.foreground, borderColor: colors.border, borderRadius: colors.radius - 4, backgroundColor: colors.input }]}
                placeholder="Leave instructions for the delivery person..."
                placeholderTextColor={colors.mutedForeground}
                value={notes}
                onChangeText={setNotes}
                multiline
                numberOfLines={3}
              />
            </View>

            {/* Summary */}
            <View style={[styles.reviewCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
              <Text style={[styles.reviewSectionTitle, { color: colors.mutedForeground }]}>Order Summary</Text>
              <View style={styles.summaryRow}>
                <Text style={[{ color: colors.mutedForeground, fontSize: 14, fontFamily: 'Inter_400Regular' }]}>Subtotal</Text>
                <Text style={[{ color: colors.foreground, fontSize: 14, fontFamily: 'Inter_500Medium' }]}>${subtotal.toFixed(2)}</Text>
              </View>
              {discount > 0 && (
                <View style={styles.summaryRow}>
                  <Text style={[{ color: colors.primary, fontSize: 14, fontFamily: 'Inter_400Regular' }]}>Promo discount</Text>
                  <Text style={[{ color: colors.primary, fontSize: 14, fontFamily: 'Inter_500Medium' }]}>-${discount.toFixed(2)}</Text>
                </View>
              )}
              <View style={styles.summaryRow}>
                <Text style={[{ color: colors.mutedForeground, fontSize: 14, fontFamily: 'Inter_400Regular' }]}>Delivery fee</Text>
                <Text style={[{ color: colors.foreground, fontSize: 14, fontFamily: 'Inter_500Medium' }]}>${deliveryFee.toFixed(2)}</Text>
              </View>
              <View style={[styles.divider, { backgroundColor: colors.border }]} />
              <View style={styles.summaryRow}>
                <Text style={[{ color: colors.foreground, fontSize: 17, fontFamily: 'Inter_700Bold' }]}>Total</Text>
                <Text style={[{ color: colors.primary, fontSize: 20, fontFamily: 'Inter_700Bold' }]}>${total.toFixed(2)}</Text>
              </View>
            </View>
          </View>
        )}
      </ScrollView>

      {/* Bottom CTA */}
      <View
        style={[
          styles.footer,
          { backgroundColor: colors.background, borderTopColor: colors.border, paddingBottom: insets.bottom + 16 },
        ]}
      >
        {/* Inline error banner — shown when order placement fails */}
        {placeError !== '' && (
          <View style={[styles.errorBanner, { backgroundColor: colors.destructive + '15', borderColor: colors.destructive, borderRadius: colors.radius - 4 }]}>
            <Ionicons name="alert-circle-outline" size={16} color={colors.destructive} />
            <Text style={[styles.errorText, { color: colors.destructive }]} numberOfLines={2}>
              {placeError}
            </Text>
            <Pressable onPress={handlePlaceOrder} disabled={placing}>
              <Text style={[styles.retryText, { color: colors.destructive }]}>Try again</Text>
            </Pressable>
          </View>
        )}

        {step < STEPS.length - 1 ? (
          <Pressable
            style={({ pressed }) => [
              styles.nextBtn,
              { backgroundColor: colors.primary, borderRadius: colors.radius, opacity: pressed ? 0.85 : 1 },
            ]}
            onPress={() => {
              if (step === 0 && !selectedAddress) return;
              setStep(step + 1);
            }}
          >
            <Text style={[styles.nextBtnText, { color: '#FFF' }]}>
              Continue to {STEPS[step + 1]}
            </Text>
            <Ionicons name="arrow-forward" size={18} color="#FFF" />
          </Pressable>
        ) : (
          <Pressable
            style={({ pressed }) => [
              styles.nextBtn,
              { backgroundColor: colors.primary, borderRadius: colors.radius, opacity: (pressed || placing) ? 0.85 : 1 },
            ]}
            onPress={handlePlaceOrder}
            disabled={placing}
          >
            {placing ? (
              <ActivityIndicator color="#FFF" />
            ) : (
              <>
                <Ionicons name="checkmark-circle-outline" size={20} color="#FFF" />
                <Text style={[styles.nextBtnText, { color: '#FFF' }]}>Place Order · ${total.toFixed(2)}</Text>
              </>
            )}
          </Pressable>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 40 },
  gateTitle: { fontSize: 22, fontFamily: 'Inter_700Bold' },
  primaryBtn: { paddingHorizontal: 28, paddingVertical: 14 },
  primaryBtnText: { fontSize: 15, fontFamily: 'Inter_600SemiBold' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    gap: 12,
  },
  headerTitle: { flex: 1, fontSize: 18, fontFamily: 'Inter_700Bold' },
  stepCounter: { fontSize: 13, fontFamily: 'Inter_400Regular' },
  stepIndicator: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingVertical: 16,
  },
  stepItem: { alignItems: 'center', gap: 4 },
  stepCircle: {
    width: 28,
    height: 28,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
  },
  stepNum: { fontSize: 12, fontFamily: 'Inter_600SemiBold' },
  stepLabel: { fontSize: 10, fontFamily: 'Inter_500Medium' },
  stepLine: { flex: 1, height: 2, marginBottom: 14, marginHorizontal: 4 },
  scrollContent: { padding: 20, paddingBottom: 40 },
  stepContent: { gap: 12 },
  stepTitle: { fontSize: 20, fontFamily: 'Inter_700Bold', marginBottom: 8 },
  addrCard: { padding: 14, borderWidth: 1.5 },
  addrRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  addrIcon: { width: 36, height: 36, alignItems: 'center', justifyContent: 'center' },
  addrLabel: { fontSize: 14, fontFamily: 'Inter_600SemiBold' },
  addrStreet: { fontSize: 13, fontFamily: 'Inter_400Regular', marginTop: 2 },
  addAddrBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    padding: 14,
    borderWidth: 1.5,
    borderStyle: 'dashed',
  },
  addAddrText: { fontSize: 14, fontFamily: 'Inter_500Medium' },
  addAddrForm: { padding: 16, borderWidth: 1, gap: 10 },
  formTitle: { fontSize: 15, fontFamily: 'Inter_600SemiBold' },
  formInput: { padding: 12, borderWidth: 1, fontSize: 14, fontFamily: 'Inter_400Regular' },
  formBtn: { padding: 12, alignItems: 'center', borderWidth: 1 },
  slotCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 16,
    borderWidth: 1.5,
  },
  slotLabel: { flex: 1, fontSize: 15 },
  reviewCard: { padding: 14, borderWidth: 1, gap: 10 },
  reviewSectionTitle: { fontSize: 12, fontFamily: 'Inter_600SemiBold', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 2 },
  reviewItem: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  reviewItemName: { flex: 1, fontSize: 13, fontFamily: 'Inter_400Regular' },
  reviewItemPrice: { fontSize: 13, fontFamily: 'Inter_500Medium' },
  promoRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  promoInput: { flex: 1, padding: 10, borderWidth: 1, fontSize: 14, fontFamily: 'Inter_400Regular' },
  notesInput: { padding: 10, borderWidth: 1, fontSize: 14, fontFamily: 'Inter_400Regular', minHeight: 72, textAlignVertical: 'top' },
  summaryRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  divider: { height: 1, marginVertical: 4 },
  footer: {
    paddingHorizontal: 16,
    paddingTop: 12,
    borderTopWidth: 1,
    gap: 10,
  },
  errorBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderWidth: 1,
  },
  errorText: {
    flex: 1,
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    lineHeight: 18,
  },
  retryText: {
    fontSize: 13,
    fontFamily: 'Inter_700Bold',
    textDecorationLine: 'underline',
    flexShrink: 0,
  },
  nextBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 16,
  },
  nextBtnText: { fontSize: 16, fontFamily: 'Inter_600SemiBold' },
});
