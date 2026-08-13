import React, { useCallback, useState } from 'react';
import {
  FlatList,

  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,

  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useListCategories, useListProducts } from '@workspace/api-client-react';
import { useColors } from '@/hooks/useColors';
import { useAuth } from '@/context/AuthContext';
import { PromoCarousel } from '@/components/PromoCarousel';
import { CategoryCard } from '@/components/CategoryCard';
import { ProductCard } from '@/components/ProductCard';
import { ProductCardSkeleton, CategoryCardSkeleton } from '@/components/SkeletonCard';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const WEB_BOTTOM = Platform.OS === 'web' ? 34 + 84 : 0;

export default function HomeScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const [refreshing, setRefreshing] = useState(false);

  const { data: categories, isLoading: catsLoading, refetch: refetchCats } = useListCategories();
  const { data: featuredData, isLoading: featuredLoading, refetch: refetchFeatured } = useListProducts({
    featured: true,
    limit: 8,
  });
  const { data: flashSaleData, isLoading: flashLoading, refetch: refetchFlash } = useListProducts({
    flashSale: true,
    limit: 8,
  });

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await Promise.all([refetchCats(), refetchFeatured(), refetchFlash()]);
    setRefreshing(false);
  }, [refetchCats, refetchFeatured, refetchFlash]);

  const greeting = () => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
  };

  const featuredProducts = featuredData?.data ?? [];
  const flashSaleProducts = flashSaleData?.data ?? [];

  return (
    <ScrollView
      style={[styles.container, { backgroundColor: colors.background }]}
      contentContainerStyle={{
        paddingTop: insets.top + WEB_TOP + 8,
        paddingBottom: insets.bottom + WEB_BOTTOM + 24,
      }}
      showsVerticalScrollIndicator={false}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={onRefresh}
          tintColor={colors.primary}
        />
      }
    >
      {/* Header */}
      <View style={styles.header}>
        <View>
          <Text style={[styles.greeting, { color: colors.mutedForeground }]}>
            {greeting()}{user?.name ? `, ${user.name.split(' ')[0]}` : ''} 👋
          </Text>
          <Text style={[styles.headline, { color: colors.foreground }]}>
            What are you{'\n'}looking for today?
          </Text>
        </View>
        <Pressable
          style={[styles.notifBtn, { backgroundColor: colors.card, borderColor: colors.border }]}
          onPress={() => router.push('/notifications')}
        >
          <Ionicons name="notifications-outline" size={22} color={colors.foreground} />
        </Pressable>
      </View>

      {/* Search bar (tap to go to search) */}
      <Pressable
        onPress={() => router.push('/(tabs)/search')}
        style={[
          styles.searchBar,
          {
            backgroundColor: colors.input,
            borderColor: colors.border,
            borderRadius: colors.radius,
          },
        ]}
      >
        <Ionicons name="search-outline" size={18} color={colors.mutedForeground} />
        <Text style={[styles.searchPlaceholder, { color: colors.mutedForeground }]}>
          Search groceries, produce...
        </Text>
      </Pressable>

      {/* Promo Carousel */}
      <PromoCarousel />

      {/* Categories */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Categories</Text>
          <Pressable onPress={() => router.push('/category/all')}>
            <Text style={[styles.seeAll, { color: colors.primary }]}>See all</Text>
          </Pressable>
        </View>
        {catsLoading ? (
          <FlatList
            horizontal
            data={[1, 2, 3, 4, 5]}
            keyExtractor={(i) => String(i)}
            renderItem={() => <CategoryCardSkeleton />}
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.hList}
            scrollEnabled={false}
          />
        ) : (
          <FlatList
            horizontal
            data={categories ?? []}
            keyExtractor={(c) => String(c.id)}
            renderItem={({ item }) => <CategoryCard category={item} />}
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.hList}
          />
        )}
      </View>

      {/* Flash Sale */}
      {(flashLoading || flashSaleProducts.length > 0) && (
        <View style={styles.section}>
          <View style={styles.sectionHeader}>
            <View style={styles.flashTitle}>
              <Ionicons name="flash" size={18} color={colors.accent} />
              <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Flash Sale</Text>
            </View>
            <Pressable onPress={() => router.push('/category/all?flashSale=true')}>
              <Text style={[styles.seeAll, { color: colors.accent }]}>See all</Text>
            </Pressable>
          </View>
          {flashLoading ? (
            <FlatList
              horizontal
              data={[1, 2, 3]}
              keyExtractor={(i) => String(i)}
              renderItem={() => <ProductCardSkeleton />}
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.hList}
              scrollEnabled={false}
            />
          ) : (
            <FlatList
              horizontal
              data={flashSaleProducts}
              keyExtractor={(p) => String(p.id)}
              renderItem={({ item }) => (
                <View style={{ width: 148, marginRight: 12 }}>
                  <ProductCard product={item} compact />
                </View>
              )}
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.hList}
            />
          )}
        </View>
      )}

      {/* Featured */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Featured</Text>
          <Pressable onPress={() => router.push('/category/all?featured=true')}>
            <Text style={[styles.seeAll, { color: colors.primary }]}>See all</Text>
          </Pressable>
        </View>
        {featuredLoading ? (
          <FlatList
            horizontal
            data={[1, 2, 3]}
            keyExtractor={(i) => String(i)}
            renderItem={() => <ProductCardSkeleton />}
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.hList}
            scrollEnabled={false}
          />
        ) : (
          <FlatList
            horizontal
            data={featuredProducts}
            keyExtractor={(p) => String(p.id)}
            renderItem={({ item }) => (
              <View style={{ width: 148, marginRight: 12 }}>
                <ProductCard product={item} compact />
              </View>
            )}
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.hList}
          />
        )}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    paddingHorizontal: 24,
    marginBottom: 16,
  },
  greeting: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    marginBottom: 4,
  },
  headline: {
    fontSize: 24,
    fontFamily: 'Inter_700Bold',
    lineHeight: 32,
  },
  notifBtn: {
    width: 42,
    height: 42,
    borderRadius: 21,
    borderWidth: 1,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 4,
  },
  searchBar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginHorizontal: 24,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderWidth: 1,
    marginBottom: 20,
  },
  searchPlaceholder: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    flex: 1,
  },
  section: { marginBottom: 24 },
  sectionHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 24,
    marginBottom: 12,
  },
  sectionTitle: {
    fontSize: 18,
    fontFamily: 'Inter_700Bold',
  },
  seeAll: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
  },
  flashTitle: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  hList: { paddingHorizontal: 24 },
});
