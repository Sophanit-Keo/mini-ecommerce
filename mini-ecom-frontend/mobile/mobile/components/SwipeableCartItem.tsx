import React, { useRef } from 'react';
import {
  Animated,
  Image,
  PanResponder,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import * as Haptics from 'expo-haptics';
import { useColors } from '@/hooks/useColors';
import type { CartItem } from '@/context/CartContext';

interface SwipeableCartItemProps {
  item: CartItem;
  onRemove: () => void;
  onUpdateQuantity: (quantity: number) => void;
}

const SWIPE_THRESHOLD = 80;

export function SwipeableCartItem({
  item,
  onRemove,
  onUpdateQuantity,
}: SwipeableCartItemProps) {
  const colors = useColors();
  const translateX = useRef(new Animated.Value(0)).current;
  const revealed = useRef(false);

  const panResponder = PanResponder.create({
    onMoveShouldSetPanResponder: (_, gestureState) =>
      Math.abs(gestureState.dx) > 8 && Math.abs(gestureState.dy) < 20,
    onPanResponderMove: (_, { dx }) => {
      if (dx < 0) {
        translateX.setValue(Math.max(dx, -120));
      } else if (revealed.current) {
        translateX.setValue(Math.min(0, -120 + dx));
      }
    },
    onPanResponderRelease: (_, { dx, vx }) => {
      if (!revealed.current && (dx < -SWIPE_THRESHOLD || vx < -0.5)) {
        Animated.spring(translateX, {
          toValue: -90,
          useNativeDriver: true,
        }).start();
        revealed.current = true;
        Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
      } else if (revealed.current && (dx > SWIPE_THRESHOLD || vx > 0.5)) {
        Animated.spring(translateX, {
          toValue: 0,
          useNativeDriver: true,
        }).start();
        revealed.current = false;
      } else {
        Animated.spring(translateX, {
          toValue: revealed.current ? -90 : 0,
          useNativeDriver: true,
        }).start();
      }
    },
  });

  return (
    <View style={styles.container}>
      {/* Delete action behind */}
      <View style={[styles.deleteAction, { backgroundColor: colors.destructive, borderRadius: colors.radius }]}>
        <Pressable onPress={onRemove} style={styles.deleteBtn}>
          <Ionicons name="trash-outline" size={22} color="#FFF" />
          <Text style={styles.deleteText}>Remove</Text>
        </Pressable>
      </View>

      <Animated.View
        style={[
          styles.card,
          {
            backgroundColor: colors.card,
            borderColor: colors.border,
            borderRadius: colors.radius,
            transform: [{ translateX }],
          },
        ]}
        {...panResponder.panHandlers}
      >
        <Image
          source={{ uri: item.image }}
          style={[styles.image, { borderRadius: colors.radius - 2 }]}
          resizeMode="cover"
        />
        <View style={styles.info}>
          <Text style={[styles.name, { color: colors.foreground }]} numberOfLines={2}>
            {item.name}
          </Text>
          <Text style={[styles.unit, { color: colors.mutedForeground }]}>
            {item.unit}
          </Text>
          <Text style={[styles.price, { color: colors.primary }]}>
            ${(item.price * item.quantity).toFixed(2)}
          </Text>
        </View>
        <View style={styles.qty}>
          <Pressable
            onPress={() => onUpdateQuantity(item.quantity - 1)}
            style={[styles.qtyBtn, { borderColor: colors.border, backgroundColor: colors.muted }]}
          >
            <Ionicons name="remove" size={14} color={colors.foreground} />
          </Pressable>
          <Text style={[styles.qtyText, { color: colors.foreground }]}>
            {item.quantity}
          </Text>
          <Pressable
            onPress={() => onUpdateQuantity(item.quantity + 1)}
            style={[styles.qtyBtn, { borderColor: colors.border, backgroundColor: colors.primary }]}
          >
            <Ionicons name="add" size={14} color="#FFF" />
          </Pressable>
        </View>
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    marginBottom: 10,
    position: 'relative',
  },
  deleteAction: {
    position: 'absolute',
    right: 0,
    top: 0,
    bottom: 0,
    width: 90,
    justifyContent: 'center',
    alignItems: 'center',
  },
  deleteBtn: {
    alignItems: 'center',
    gap: 4,
  },
  deleteText: {
    color: '#FFF',
    fontSize: 10,
    fontFamily: 'Inter_500Medium',
  },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    gap: 12,
    borderWidth: 1,
  },
  image: {
    width: 64,
    height: 64,
  },
  info: {
    flex: 1,
    gap: 2,
  },
  name: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
    lineHeight: 19,
  },
  unit: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  price: {
    fontSize: 15,
    fontFamily: 'Inter_700Bold',
    marginTop: 2,
  },
  qty: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  qtyBtn: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyText: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
    minWidth: 16,
    textAlign: 'center',
  },
});
