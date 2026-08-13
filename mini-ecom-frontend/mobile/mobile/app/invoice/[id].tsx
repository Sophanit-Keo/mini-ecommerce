import React from 'react';
import { Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useGetOrderInvoice } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

export default function InvoiceScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useColors();
  const insets = useSafeAreaInsets();

  const { data: invoice, isLoading, isError, error, refetch } = useGetOrderInvoice(Number(id));

  // A pending/unapproved order has no invoice yet — that's a normal state
  // (the seller hasn't issued one via the admin console), not an error.
  const notYetIssued = error?.status === 404;

  const Header = (
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
      <Text style={[styles.headerTitle, { color: colors.foreground }]}>Receipt</Text>
      <View style={{ width: 24 }} />
    </View>
  );

  if (isLoading) {
    return (
      <View style={[styles.container, { backgroundColor: colors.background }]}>
        {Header}
        <View style={styles.center}>
          <Ionicons name="receipt-outline" size={48} color={colors.primary} />
          <Text style={[styles.centerText, { color: colors.mutedForeground }]}>
            Loading receipt...
          </Text>
        </View>
      </View>
    );
  }

  if (notYetIssued) {
    return (
      <View style={[styles.container, { backgroundColor: colors.background }]}>
        {Header}
        <View style={styles.center}>
          <Ionicons name="hourglass-outline" size={48} color={colors.mutedForeground} />
          <Text style={[styles.centerTitle, { color: colors.foreground }]}>No receipt yet</Text>
          <Text style={[styles.centerText, { color: colors.mutedForeground }]}>
            A receipt is issued once the store approves this order.
          </Text>
        </View>
      </View>
    );
  }

  if (isError || !invoice) {
    return (
      <View style={[styles.container, { backgroundColor: colors.background }]}>
        {Header}
        <View style={styles.center}>
          <Ionicons name="alert-circle-outline" size={48} color={colors.destructive} />
          <Text style={[styles.centerTitle, { color: colors.foreground }]}>
            Couldn't load receipt
          </Text>
          <Pressable onPress={() => refetch()}>
            <Text style={[styles.retryText, { color: colors.primary }]}>Try again</Text>
          </Pressable>
        </View>
      </View>
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {Header}
      <ScrollView
        contentContainerStyle={[styles.scrollContent, { paddingBottom: insets.bottom + 40 }]}
        showsVerticalScrollIndicator={false}
      >
        {/* Store header */}
        <View style={styles.storeHeader}>
          <View style={[styles.storeIcon, { backgroundColor: colors.secondary, borderRadius: colors.radius }]}>
            <Ionicons name="leaf" size={28} color={colors.primary} />
          </View>
          <Text style={[styles.storeName, { color: colors.foreground }]}>Grocerly</Text>
          <Text style={[styles.invoiceNumber, { color: colors.mutedForeground }]}>
            Invoice #{invoice.invoiceNumber}
          </Text>
          <Text style={[styles.issuedAt, { color: colors.mutedForeground }]}>
            Issued{' '}
            {new Date(invoice.issuedAt).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
            })}
          </Text>
        </View>

        {/* Customer & delivery address */}
        <View style={[styles.card, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <Text style={[styles.cardTitle, { color: colors.foreground }]}>Billed To</Text>
          <Text style={[styles.customerName, { color: colors.foreground }]}>{invoice.customer.name}</Text>
          <Text style={[styles.customerEmail, { color: colors.mutedForeground }]}>{invoice.customer.email}</Text>

          <View style={[styles.divider, { backgroundColor: colors.border }]} />

          <Text style={[styles.cardTitle, { color: colors.foreground }]}>Delivery Address</Text>
          <Text style={[styles.addressText, { color: colors.foreground }]}>
            {invoice.address.street}
          </Text>
          <Text style={[styles.addressText, { color: colors.mutedForeground }]}>
            {invoice.address.city}, {invoice.address.state} {invoice.address.zipCode}
          </Text>
        </View>

        {/* Line items */}
        <View style={[styles.card, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <Text style={[styles.cardTitle, { color: colors.foreground }]}>Items ({invoice.items.length})</Text>
          {invoice.items.map((item) => (
            <View key={item.id} style={styles.itemRow}>
              <View style={{ flex: 1 }}>
                <Text style={[styles.itemName, { color: colors.foreground }]} numberOfLines={2}>
                  {item.name}
                </Text>
                <Text style={[styles.itemMeta, { color: colors.mutedForeground }]}>
                  {item.quantity} × ${item.price.toFixed(2)} · {item.unit}
                </Text>
              </View>
              <Text style={[styles.itemTotal, { color: colors.foreground }]}>
                ${(item.price * item.quantity).toFixed(2)}
              </Text>
            </View>
          ))}
        </View>

        {/* Totals */}
        <View style={[styles.card, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <View style={styles.summaryRow}>
            <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Subtotal</Text>
            <Text style={[styles.summaryValue, { color: colors.foreground }]}>
              ${invoice.subtotal.toFixed(2)}
            </Text>
          </View>
          {invoice.discount > 0 && (
            <View style={styles.summaryRow}>
              <Text style={[styles.summaryLabel, { color: colors.primary }]}>Discount</Text>
              <Text style={[styles.summaryValue, { color: colors.primary }]}>
                -${invoice.discount.toFixed(2)}
              </Text>
            </View>
          )}
          <View style={styles.summaryRow}>
            <Text style={[styles.summaryLabel, { color: colors.mutedForeground }]}>Delivery fee</Text>
            <Text style={[styles.summaryValue, { color: colors.foreground }]}>
              ${invoice.deliveryFee.toFixed(2)}
            </Text>
          </View>
          <View style={[styles.divider, { backgroundColor: colors.border }]} />
          <View style={styles.summaryRow}>
            <Text style={[styles.totalLabel, { color: colors.foreground }]}>Total</Text>
            <Text style={[styles.totalValue, { color: colors.primary }]}>
              {invoice.currency} ${invoice.total.toFixed(2)}
            </Text>
          </View>
          <View style={[styles.paymentMethodRow, { backgroundColor: colors.secondary, borderRadius: colors.radius - 4 }]}>
            <Ionicons name="card-outline" size={16} color={colors.primary} />
            <Text style={[styles.paymentMethodText, { color: colors.secondaryForeground }]}>
              {invoice.paymentMethod.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
            </Text>
          </View>
        </View>

        <Text style={[styles.footerNote, { color: colors.mutedForeground }]}>
          Order placed{' '}
          {new Date(invoice.placedAt).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
          })}
        </Text>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
  },
  headerTitle: { fontSize: 18, fontFamily: 'Inter_700Bold' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 32 },
  centerTitle: { fontSize: 18, fontFamily: 'Inter_600SemiBold', textAlign: 'center' },
  centerText: { fontSize: 14, fontFamily: 'Inter_400Regular', textAlign: 'center' },
  retryText: { fontSize: 14, fontFamily: 'Inter_600SemiBold', marginTop: 4 },
  scrollContent: { padding: 16, gap: 12 },
  storeHeader: { alignItems: 'center', gap: 4, paddingVertical: 16 },
  storeIcon: {
    width: 56,
    height: 56,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 6,
  },
  storeName: { fontSize: 20, fontFamily: 'Inter_700Bold' },
  invoiceNumber: { fontSize: 13, fontFamily: 'Inter_500Medium', marginTop: 4 },
  issuedAt: { fontSize: 12, fontFamily: 'Inter_400Regular' },
  card: { padding: 14, borderWidth: 1, gap: 4 },
  cardTitle: { fontSize: 13, fontFamily: 'Inter_600SemiBold', marginBottom: 4 },
  customerName: { fontSize: 15, fontFamily: 'Inter_500Medium' },
  customerEmail: { fontSize: 13, fontFamily: 'Inter_400Regular' },
  addressText: { fontSize: 14, fontFamily: 'Inter_400Regular', lineHeight: 20 },
  divider: { height: 1, marginVertical: 10 },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: 12,
    paddingVertical: 6,
  },
  itemName: { fontSize: 13, fontFamily: 'Inter_500Medium', lineHeight: 18 },
  itemMeta: { fontSize: 12, fontFamily: 'Inter_400Regular', marginTop: 2 },
  itemTotal: { fontSize: 14, fontFamily: 'Inter_600SemiBold' },
  summaryRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 2 },
  summaryLabel: { fontSize: 14, fontFamily: 'Inter_400Regular' },
  summaryValue: { fontSize: 14, fontFamily: 'Inter_500Medium' },
  totalLabel: { fontSize: 16, fontFamily: 'Inter_700Bold' },
  totalValue: { fontSize: 18, fontFamily: 'Inter_700Bold' },
  paymentMethodRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 10,
    marginTop: 8,
  },
  paymentMethodText: { fontSize: 13, fontFamily: 'Inter_500Medium' },
  footerNote: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
    marginTop: 4,
  },
});
