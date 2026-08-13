import React, { useState } from 'react';
import {
  FlatList,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useListProducts, useGetCategory } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { ProductCard } from '@/components/ProductCard';
import { ProductCardSkeleton } from '@/components/SkeletonCard';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const SORT_OPTIONS = [
  { label: 'Default', value: undefined },
  { label: 'Price: Low–High', value: 'price_asc' as const },
  { label: 'Price: High–Low', value: 'price_desc' as const },
  { label: 'Top Rated', value: 'rating' as const },
  { label: 'Newest', value: 'newest' as const },
];

export default function CategoryScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const [sort, setSort] = useState<'price_asc' | 'price_desc' | 'rating' | 'newest' | undefined>();
  const [showSortSheet, setShowSortSheet] = useState(false);

  const categoryId = id === 'all' ? undefined : Number(id);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data: category } = useGetCategory(Number(id), {
    query: { enabled: id !== 'all' && !isNaN(Number(id)) } as any,
  });

  const { data, isLoading } = useListProducts({
    categoryId,
    sort,
    limit: 40,
  });

  const products = data?.data ?? [];
  const pageTitle = category?.name ?? 'All Products';

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {/* Header */}
      <View
        style={[
          styles.header,
          {
            paddingTop: insets.top + WEB_TOP + 12,
            backgroundColor: colors.background,
            borderBottomColor: colors.border,
          },
        ]}
      >
        <Pressable onPress={() => router.back()} style={styles.backBtn} hitSlop={8}>
          <Ionicons name="chevron-back" size={24} color={colors.foreground} />
        </Pressable>
        <Text style={[styles.pageTitle, { color: colors.foreground }]} numberOfLines={1}>
          {pageTitle}
        </Text>
        <Pressable
          style={[styles.sortBtn, { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius - 4 }]}
          onPress={() => setShowSortSheet(true)}
        >
          <Ionicons name="funnel-outline" size={16} color={colors.foreground} />
          <Text style={[styles.sortBtnText, { color: colors.foreground }]}>Sort</Text>
        </Pressable>
      </View>

      {/* Sort Sheet */}
      {showSortSheet && (
        <Pressable
          style={styles.overlay}
          onPress={() => setShowSortSheet(false)}
        >
          <Pressable
            style={[
              styles.sortSheet,
              { backgroundColor: colors.card, borderColor: colors.border, borderRadius: colors.radius + 4 },
            ]}
            onPress={(e) => e.stopPropagation()}
          >
            <View style={[styles.sheetHandle, { backgroundColor: colors.border }]} />
            <Text style={[styles.sheetTitle, { color: colors.foreground }]}>Sort by</Text>
            {SORT_OPTIONS.map((opt) => (
              <Pressable
                key={opt.label}
                style={[
                  styles.sortOption,
                  {
                    backgroundColor: sort === opt.value ? colors.secondary : 'transparent',
                    borderRadius: colors.radius - 4,
                  },
                ]}
                onPress={() => {
                  setSort(opt.value);
                  setShowSortSheet(false);
                }}
              >
                <Text
                  style={[
                    styles.sortOptionText,
                    {
                      color: sort === opt.value ? colors.primary : colors.foreground,
                      fontFamily: sort === opt.value ? 'Inter_600SemiBold' : 'Inter_400Regular',
                    },
                  ]}
                >
                  {opt.label}
                </Text>
                {sort === opt.value && (
                  <Ionicons name="checkmark" size={18} color={colors.primary} />
                )}
              </Pressable>
            ))}
          </Pressable>
        </Pressable>
      )}

      {isLoading ? (
        <FlatList
          data={[1, 2, 3, 4, 5, 6]}
          keyExtractor={(i) => String(i)}
          numColumns={2}
          columnWrapperStyle={styles.columnWrapper}
          contentContainerStyle={styles.listContent}
          renderItem={() => <View style={{ flex: 1, marginHorizontal: 4 }}><ProductCardSkeleton /></View>}
        />
      ) : (
        <FlatList
          data={products}
          keyExtractor={(p) => String(p.id)}
          numColumns={2}
          columnWrapperStyle={styles.columnWrapper}
          contentContainerStyle={[
            styles.listContent,
            { paddingBottom: insets.bottom + 100 },
          ]}
          ListHeaderComponent={
            <Text style={[styles.count, { color: colors.mutedForeground }]}>
              {products.length} products
            </Text>
          }
          ListEmptyComponent={
            <View style={styles.empty}>
              <Ionicons name="basket-outline" size={48} color={colors.mutedForeground} />
              <Text style={[styles.emptyTitle, { color: colors.foreground }]}>No products found</Text>
            </View>
          }
          renderItem={({ item }) => (
            <View style={{ flex: 1, marginHorizontal: 4 }}>
              <ProductCard product={item} />
            </View>
          )}
        />
      )}
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
  backBtn: { padding: 4 },
  pageTitle: {
    flex: 1,
    fontSize: 18,
    fontFamily: 'Inter_700Bold',
  },
  sortBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderWidth: 1,
  },
  sortBtnText: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
  },
  count: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    marginBottom: 8,
  },
  listContent: {
    padding: 12,
  },
  columnWrapper: {
    marginBottom: 8,
    gap: 8,
  },
  empty: {
    alignItems: 'center',
    paddingTop: 60,
    gap: 10,
  },
  emptyTitle: {
    fontSize: 18,
    fontFamily: 'Inter_600SemiBold',
  },
  overlay: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'flex-end',
    zIndex: 100,
  },
  sortSheet: {
    padding: 20,
    paddingBottom: 32,
    borderWidth: 1,
    gap: 4,
  },
  sheetHandle: {
    width: 36,
    height: 4,
    borderRadius: 2,
    alignSelf: 'center',
    marginBottom: 12,
  },
  sheetTitle: {
    fontSize: 17,
    fontFamily: 'Inter_700Bold',
    marginBottom: 8,
  },
  sortOption: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    paddingHorizontal: 10,
  },
  sortOptionText: {
    fontSize: 15,
  },
});
