import React, { useState } from 'react';
import {
  FlatList,
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
import * as Haptics from 'expo-haptics';
import { useGetProduct, useGetProductSubstitutes } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useCart } from '@/context/CartContext';
import { useWishlist } from '@/context/WishlistContext';
import { ProductCard } from '@/components/ProductCard';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

export default function ProductDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { addItem } = useCart();
  const { isInWishlist, toggle } = useWishlist();
  const [quantity, setQuantity] = useState(1);

  const { data: product, isLoading, isError } = useGetProduct(Number(id));
  const { data: substitutes } = useGetProductSubstitutes(Number(id));

  const wishlisted = product ? isInWishlist(product.id) : false;
  const discount = product?.originalPrice
    ? Math.round((1 - product.price / product.originalPrice) * 100)
    : null;

  if (isLoading) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Ionicons name="leaf-outline" size={40} color={colors.primary} />
        <Text style={[styles.loadingText, { color: colors.mutedForeground }]}>Loading...</Text>
      </View>
    );
  }

  if (isError || !product) {
    return (
      <View style={[styles.center, { backgroundColor: colors.background }]}>
        <Ionicons name="alert-circle-outline" size={48} color={colors.mutedForeground} />
        <Text style={[styles.errorTitle, { color: colors.foreground }]}>Product not found</Text>
        <Pressable onPress={() => router.back()}>
          <Text style={[styles.backLink, { color: colors.primary }]}>Go back</Text>
        </Pressable>
      </View>
    );
  }

  const handleAddToCart = () => {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    addItem({
      productId: product.id,
      name: product.name,
      price: product.price,
      unit: product.unit,
      image: product.image,
      quantity,
    });
    router.push('/(tabs)/cart');
  };

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {/* Back & Wishlist overlay */}
      <View
        style={[
          styles.overlay,
          { paddingTop: insets.top + WEB_TOP + 8 },
        ]}
      >
        <Pressable
          style={[styles.overlayBtn, { backgroundColor: colors.card }]}
          onPress={() => router.back()}
        >
          <Ionicons name="chevron-back" size={20} color={colors.foreground} />
        </Pressable>
        <Pressable
          style={[styles.overlayBtn, { backgroundColor: colors.card }]}
          onPress={() => toggle(product.id)}
        >
          <Ionicons
            name={wishlisted ? 'heart' : 'heart-outline'}
            size={20}
            color={wishlisted ? colors.destructive : colors.foreground}
          />
        </Pressable>
      </View>

      <ScrollView showsVerticalScrollIndicator={false}>
        {/* Product image */}
        <View style={[styles.imageContainer, { backgroundColor: colors.muted }]}>
          <Image
            source={{ uri: product.image }}
            style={styles.image}
            resizeMode="cover"
          />
          {discount && (
            <View style={[styles.discountBadge, { backgroundColor: colors.primary }]}>
              <Text style={styles.discountText}>-{discount}% OFF</Text>
            </View>
          )}
        </View>

        <View style={[styles.detailContainer, { backgroundColor: colors.background }]}>
          {/* Category & Name */}
          <Text style={[styles.categoryLabel, { color: colors.primary }]}>
            {product.category.name}
          </Text>
          <Text style={[styles.productName, { color: colors.foreground }]}>
            {product.name}
          </Text>
          <Text style={[styles.unit, { color: colors.mutedForeground }]}>{product.unit}</Text>

          {/* Rating */}
          <View style={styles.ratingRow}>
            {[1, 2, 3, 4, 5].map((star) => (
              <Ionicons
                key={star}
                name={star <= Math.round(product.rating) ? 'star' : 'star-outline'}
                size={16}
                color="#FBBF24"
              />
            ))}
            <Text style={[styles.ratingText, { color: colors.mutedForeground }]}>
              {product.rating.toFixed(1)} · {product.reviewCount} reviews
            </Text>
          </View>

          {/* Price */}
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

            {/* Quantity selector */}
            <View style={[styles.qtySelector, { borderColor: colors.border, borderRadius: colors.radius }]}>
              <Pressable
                onPress={() => setQuantity(Math.max(1, quantity - 1))}
                style={[styles.qtyBtn, { backgroundColor: colors.muted }]}
              >
                <Ionicons name="remove" size={18} color={colors.foreground} />
              </Pressable>
              <Text style={[styles.qtyText, { color: colors.foreground }]}>{quantity}</Text>
              <Pressable
                onPress={() => setQuantity(quantity + 1)}
                style={[styles.qtyBtn, { backgroundColor: colors.primary }]}
              >
                <Ionicons name="add" size={18} color="#FFF" />
              </Pressable>
            </View>
          </View>

          {/* Description */}
          <View style={[styles.descSection, { borderColor: colors.border }]}>
            <Text style={[styles.descTitle, { color: colors.foreground }]}>About this product</Text>
            <Text style={[styles.description, { color: colors.mutedForeground }]}>
              {product.description}
            </Text>
          </View>

          {/* Flash sale info */}
          {product.isFlashSale && (
            <View style={[styles.flashBanner, { backgroundColor: colors.accent + '15', borderRadius: colors.radius }]}>
              <Ionicons name="flash" size={16} color={colors.accent} />
              <Text style={[styles.flashText, { color: colors.accent }]}>
                Flash Sale — Limited time offer!
              </Text>
            </View>
          )}

          {/* Substitutes */}
          {(substitutes?.length ?? 0) > 0 && (
            <View style={styles.subsSection}>
              <Text style={[styles.subsTitle, { color: colors.foreground }]}>You might also like</Text>
              <FlatList
                horizontal
                data={substitutes}
                keyExtractor={(p) => String(p.id)}
                renderItem={({ item }) => (
                  <View style={{ width: 148, marginRight: 12 }}>
                    <ProductCard product={item} compact />
                  </View>
                )}
                showsHorizontalScrollIndicator={false}
              />
            </View>
          )}

          <View style={{ height: 100 }} />
        </View>
      </ScrollView>

      {/* Add to cart footer */}
      <View
        style={[
          styles.footer,
          {
            backgroundColor: colors.background,
            borderTopColor: colors.border,
            paddingBottom: insets.bottom + 16,
          },
        ]}
      >
        <View>
          <Text style={[styles.footerTotal, { color: colors.mutedForeground }]}>Total price</Text>
          <Text style={[styles.footerPrice, { color: colors.foreground }]}>
            ${(product.price * quantity).toFixed(2)}
          </Text>
        </View>
        <Pressable
          style={({ pressed }) => [
            styles.addBtn,
            {
              backgroundColor: colors.primary,
              borderRadius: colors.radius,
              opacity: pressed ? 0.85 : 1,
              flex: 1,
              marginLeft: 16,
            },
          ]}
          onPress={handleAddToCart}
        >
          <Ionicons name="cart-outline" size={20} color="#FFF" />
          <Text style={styles.addBtnText}>Add to Cart</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  loadingText: { fontSize: 14, fontFamily: 'Inter_400Regular' },
  errorTitle: { fontSize: 18, fontFamily: 'Inter_600SemiBold' },
  backLink: { fontSize: 14, fontFamily: 'Inter_500Medium' },
  overlay: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    zIndex: 10,
  },
  overlayBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  imageContainer: {
    height: 300,
    position: 'relative',
  },
  image: {
    width: '100%',
    height: '100%',
  },
  discountBadge: {
    position: 'absolute',
    bottom: 16,
    left: 16,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 20,
  },
  discountText: {
    color: '#FFF',
    fontSize: 12,
    fontFamily: 'Inter_700Bold',
  },
  detailContainer: {
    padding: 20,
    gap: 10,
  },
  categoryLabel: {
    fontSize: 13,
    fontFamily: 'Inter_600SemiBold',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  productName: {
    fontSize: 24,
    fontFamily: 'Inter_700Bold',
    lineHeight: 32,
  },
  unit: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
  },
  ratingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  ratingText: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    marginLeft: 4,
  },
  priceRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 4,
  },
  price: {
    fontSize: 28,
    fontFamily: 'Inter_700Bold',
  },
  originalPrice: {
    fontSize: 15,
    textDecorationLine: 'line-through',
    fontFamily: 'Inter_400Regular',
  },
  qtySelector: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    overflow: 'hidden',
  },
  qtyBtn: {
    width: 38,
    height: 38,
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyText: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
    paddingHorizontal: 16,
  },
  descSection: {
    paddingTop: 16,
    borderTopWidth: 1,
    gap: 8,
  },
  descTitle: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
  description: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    lineHeight: 22,
  },
  flashBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 12,
  },
  flashText: {
    fontSize: 13,
    fontFamily: 'Inter_600SemiBold',
  },
  subsSection: {
    paddingTop: 16,
    gap: 12,
  },
  subsTitle: {
    fontSize: 18,
    fontFamily: 'Inter_700Bold',
  },
  footer: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 16,
    paddingTop: 12,
    borderTopWidth: 1,
  },
  footerTotal: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  footerPrice: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
  },
  addBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 14,
  },
  addBtnText: {
    color: '#FFF',
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
});
