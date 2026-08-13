import React from 'react';
import {
  FlatList,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useListProducts } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useWishlist } from '@/context/WishlistContext';
import { ProductCard } from '@/components/ProductCard';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;

export default function WishlistScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { productIds } = useWishlist();

  // Fetch all products and filter by wishlist IDs
  const { data, isLoading } = useListProducts({ limit: 100 });
  const allProducts = data?.data ?? [];
  const wishlistProducts = allProducts.filter((p) => productIds.includes(p.id));

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
        <Text style={[styles.headerTitle, { color: colors.foreground }]}>Wishlist</Text>
        <View style={{ width: 32 }} />
      </View>

      <FlatList
        data={isLoading ? [] : wishlistProducts}
        keyExtractor={(p) => String(p.id)}
        numColumns={2}
        columnWrapperStyle={styles.columnWrapper}
        contentContainerStyle={[
          styles.listContent,
          { paddingBottom: insets.bottom + 40 },
        ]}
        ListHeaderComponent={
          wishlistProducts.length > 0 ? (
            <Text style={[styles.count, { color: colors.mutedForeground }]}>
              {wishlistProducts.length} saved items
            </Text>
          ) : null
        }
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <View style={[styles.emptyIcon, { backgroundColor: colors.secondary, borderRadius: 60 }]}>
                <Ionicons name="heart-outline" size={56} color={colors.primary} />
              </View>
              <Text style={[styles.emptyTitle, { color: colors.foreground }]}>Your wishlist is empty</Text>
              <Text style={[styles.emptySubtitle, { color: colors.mutedForeground }]}>
                Save items you love for later
              </Text>
              <Pressable
                style={[styles.browseBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
                onPress={() => router.push('/')}
              >
                <Text style={[styles.browseBtnText, { color: '#FFF' }]}>Browse Products</Text>
              </Pressable>
            </View>
          ) : null
        }
        renderItem={({ item }) => (
          <View style={{ flex: 1, marginHorizontal: 4 }}>
            <ProductCard product={item} />
          </View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    gap: 12,
  },
  headerTitle: { flex: 1, fontSize: 18, fontFamily: 'Inter_700Bold' },
  count: { fontSize: 13, fontFamily: 'Inter_400Regular', marginBottom: 8 },
  listContent: { padding: 12 },
  columnWrapper: { marginBottom: 8, gap: 8 },
  empty: { alignItems: 'center', paddingTop: 80, gap: 12 },
  emptyIcon: { width: 120, height: 120, alignItems: 'center', justifyContent: 'center', marginBottom: 8 },
  emptyTitle: { fontSize: 22, fontFamily: 'Inter_700Bold' },
  emptySubtitle: { fontSize: 15, fontFamily: 'Inter_400Regular', textAlign: 'center' },
  browseBtn: { marginTop: 8, paddingHorizontal: 32, paddingVertical: 14 },
  browseBtnText: { fontSize: 15, fontFamily: 'Inter_600SemiBold' },
});
