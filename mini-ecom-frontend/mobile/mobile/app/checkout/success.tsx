import React, { useEffect, useRef } from 'react';
import { Animated, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as Haptics from 'expo-haptics';
import { useColors } from '@/hooks/useColors';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

export default function CheckoutSuccessScreen() {
  const { orderId } = useLocalSearchParams<{ orderId: string }>();
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const scale = useRef(new Animated.Value(0)).current;
  const opacity = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    Animated.parallel([
      Animated.spring(scale, { toValue: 1, useNativeDriver: true, tension: 50, friction: 7 }),
      Animated.timing(opacity, { toValue: 1, duration: 600, useNativeDriver: true }),
    ]).start();
  }, [opacity, scale]);

  return (
    <View
      style={[
        styles.container,
        {
          backgroundColor: colors.background,
          paddingTop: insets.top + WEB_TOP,
          paddingBottom: insets.bottom,
        },
      ]}
    >
      <Animated.View style={[styles.content, { opacity }]}>
        <Animated.View
          style={[
            styles.iconContainer,
            {
              backgroundColor: colors.secondary,
              borderRadius: 80,
              transform: [{ scale }],
            },
          ]}
        >
          <Ionicons name="checkmark-circle" size={80} color={colors.primary} />
        </Animated.View>

        <Text style={[styles.title, { color: colors.foreground }]}>Order Placed!</Text>
        <Text style={[styles.orderId, { color: colors.primary }]}>Order #{orderId}</Text>
        <Text style={[styles.subtitle, { color: colors.mutedForeground }]}>
          Your fresh groceries are being prepared and will be with you soon.
        </Text>

        <View style={[styles.infoCard, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius }]}>
          <View style={styles.infoRow}>
            <Ionicons name="time-outline" size={20} color={colors.primary} />
            <View>
              <Text style={[styles.infoLabel, { color: colors.mutedForeground }]}>Estimated delivery</Text>
              <Text style={[styles.infoValue, { color: colors.foreground }]}>45 – 60 minutes</Text>
            </View>
          </View>
          <View style={[styles.divider, { backgroundColor: colors.border }]} />
          <View style={styles.infoRow}>
            <Ionicons name="location-outline" size={20} color={colors.primary} />
            <View>
              <Text style={[styles.infoLabel, { color: colors.mutedForeground }]}>Tracking</Text>
              <Text style={[styles.infoValue, { color: colors.foreground }]}>Live updates in your orders</Text>
            </View>
          </View>
        </View>
      </Animated.View>

      <View style={styles.actions}>
        <Pressable
          style={({ pressed }) => [
            styles.trackBtn,
            { backgroundColor: colors.primary, borderRadius: colors.radius, opacity: pressed ? 0.85 : 1 },
          ]}
          onPress={() => {
            router.dismissAll();
            router.push(`/order/${orderId}`);
          }}
        >
          <Ionicons name="map-outline" size={18} color="#FFF" />
          <Text style={[styles.trackBtnText, { color: '#FFF' }]}>Track Order</Text>
        </Pressable>
        <Pressable
          style={({ pressed }) => [
            styles.homeBtn,
            { borderColor: colors.border, borderRadius: colors.radius, opacity: pressed ? 0.85 : 1 },
          ]}
          onPress={() => {
            router.dismissAll();
            router.push('/');
          }}
        >
          <Text style={[styles.homeBtnText, { color: colors.foreground }]}>Continue Shopping</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  content: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
    gap: 16,
  },
  iconContainer: {
    width: 160,
    height: 160,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 8,
  },
  title: {
    fontSize: 32,
    fontFamily: 'Inter_700Bold',
  },
  orderId: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
  subtitle: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
    lineHeight: 22,
  },
  infoCard: {
    width: '100%',
    borderWidth: 1,
    padding: 16,
    gap: 12,
    marginTop: 8,
  },
  infoRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  infoLabel: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  infoValue: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
    marginTop: 2,
  },
  divider: { height: 1 },
  actions: {
    padding: 20,
    gap: 10,
  },
  trackBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 16,
  },
  trackBtnText: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
  homeBtn: {
    paddingVertical: 14,
    alignItems: 'center',
    borderWidth: 1,
  },
  homeBtnText: {
    fontSize: 15,
    fontFamily: 'Inter_500Medium',
  },
});
