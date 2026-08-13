import React, { useCallback } from 'react';
import {
  Image,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useColors } from '@/hooks/useColors';
import { useCart } from '@/context/CartContext';
import { useWishlist } from '@/context/WishlistContext';
import type { Product } from '@workspace/api-client-react';

interface ProductCardProps {
  product: Product;
  compact?: boolean;
}

export function ProductCard({ product, compact = false }: ProductCardProps) {
  const colors = useColors();
  const { addItem } = useCart();
  const { isInWishlist, toggle } = useWishlist();
  const wishlisted = isInWishlist(product.id);
  const discount = product.originalPrice
    ? Math.round((1 - product.price / product.originalPrice) * 100)
    : null;

  const handleAddToCart = useCallback(() => {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    addItem({
      productId: product.id,
      name: product.name,
      price: product.price,
      unit: product.unit,
      image: product.image,
    });
  }, [addItem, product]);

  const handleWishlist = useCallback(() => {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    toggle(product.id);
  }, [toggle, product.id]);

  return (
    <Pressable
      style={({ pressed }) => [
        styles.card,
        {
          backgroundColor: colors.card,
          borderColor: colors.border,
          borderRadius: colors.radius,
          opacity: pressed ? 0.94 : 1,
          width: compact ? 148 : '100%',
        },
      ]}
      onPress={() => router.push(`/product/${product.id}`)}
    >
      <View style={[styles.imageContainer, { borderRadius: colors.radius - 2 }]}>
        <Image
          source={{ uri: product.image }}
          style={styles.image}
          resizeMode="cover"
        />
        {product.isFlashSale && (
          <View style={[styles.saleBadge, { backgroundColor: colors.accent }]}>
            <Text style={styles.saleBadgeText}>SALE</Text>
          </View>
        )}
        {discount && (
          <View style={[styles.discountBadge, { backgroundColor: colors.primary }]}>
            <Text style={styles.discountText}>-{discount}%</Text>
          </View>
        )}
        <Pressable
          style={[styles.wishlistBtn, { backgroundColor: colors.card }]}
          onPress={handleWishlist}
          hitSlop={8}
        >
          <Ionicons
            name={wishlisted ? 'heart' : 'heart-outline'}
            size={16}
            color={wishlisted ? colors.destructive : colors.mutedForeground}
          />
        </Pressable>
      </View>

      <View style={styles.info}>
        <Text
          style={[styles.name, { color: colors.foreground }]}
          numberOfLines={2}
        >
          {product.name}
        </Text>
        <Text style={[styles.unit, { color: colors.mutedForeground }]}>
          {product.unit}
        </Text>

        <View style={styles.ratingRow}>
          <Ionicons name="star" size={11} color="#FBBF24" />
          <Text style={[styles.rating, { color: colors.mutedForeground }]}>
            {product.rating.toFixed(1)} ({product.reviewCount})
          </Text>
        </View>

        <View style={styles.priceRow}>
          <View>
            <Text style={[styles.price, { color: colors.foreground }]}>
              ${product.price.toFixed(2)}
            </Text>
            {product.originalPrice && (
              <Text style={[styles.originalPrice, { color: colors.mutedForeground }]}>
                ${product.originalPrice.toFixed(2)}
              </Text>
            )}
          </View>
          <Pressable
            style={({ pressed }) => [
              styles.addBtn,
              {
                backgroundColor: colors.primary,
                borderRadius: colors.radius - 4,
                opacity: pressed ? 0.8 : 1,
              },
            ]}
            onPress={handleAddToCart}
          >
            <Ionicons name="add" size={18} color={colors.primaryForeground} />
          </Pressable>
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    overflow: 'hidden',
    marginBottom: 8,
  },
  imageContainer: {
    position: 'relative',
    overflow: 'hidden',
    height: 130,
    backgroundColor: '#F5F5F5',
  },
  image: {
    width: '100%',
    height: '100%',
  },
  saleBadge: {
    position: 'absolute',
    top: 8,
    left: 8,
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  saleBadgeText: {
    fontSize: 9,
    fontWeight: '700',
    color: '#FFFFFF',
    letterSpacing: 0.5,
  },
  discountBadge: {
    position: 'absolute',
    top: 8,
    right: 34,
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  discountText: {
    fontSize: 9,
    fontWeight: '700',
    color: '#FFFFFF',
  },
  wishlistBtn: {
    position: 'absolute',
    top: 6,
    right: 6,
    width: 26,
    height: 26,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.1,
    shadowRadius: 2,
    elevation: 2,
  },
  info: {
    padding: 10,
    gap: 2,
  },
  name: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
    lineHeight: 18,
  },
  unit: {
    fontSize: 11,
    fontFamily: 'Inter_400Regular',
  },
  ratingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    marginTop: 2,
  },
  rating: {
    fontSize: 10,
    fontFamily: 'Inter_400Regular',
  },
  priceRow: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    marginTop: 6,
  },
  price: {
    fontSize: 15,
    fontFamily: 'Inter_700Bold',
  },
  originalPrice: {
    fontSize: 11,
    textDecorationLine: 'line-through',
    fontFamily: 'Inter_400Regular',
  },
  addBtn: {
    width: 30,
    height: 30,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
