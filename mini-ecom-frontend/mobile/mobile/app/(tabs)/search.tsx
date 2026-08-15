import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  FlatList,
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
import { useListProducts, useListCategories } from '@workspace/api-client-react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useColors } from '@/hooks/useColors';
import { ProductCard } from '@/components/ProductCard';
import { ProductCardSkeleton } from '@/components/SkeletonCard';

const RECENT_KEY = '@grocerly/recentSearches';
const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const WEB_BOTTOM = Platform.OS === 'web' ? 34 + 84 : 0;

const TRENDING = [
  'Organic produce', 'Chicken breast', 'Whole milk', 'Sourdough bread',
  'Salmon fillet', 'Greek yogurt', 'Olive oil', 'Sparkling water',
];

export default function SearchScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const inputRef = useRef<TextInput>(null);
  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [recentSearches, setRecentSearches] = useState<string[]>([]);
  const [focused, setFocused] = useState(false);

  useEffect(() => {
    AsyncStorage.getItem(RECENT_KEY).then((raw) => {
      if (raw) setRecentSearches(JSON.parse(raw) as string[]);
    });
  }, []);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 400);
    return () => clearTimeout(t);
  }, [query]);

  const { data: categories } = useListCategories();
  const { data: searchData, isLoading: searching } = useListProducts({
    q: debouncedQuery,
    limit: 30,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    query: { enabled: debouncedQuery.length > 1 } as any,
  });

  const saveSearch = useCallback(async (term: string) => {
    const updated = [term, ...recentSearches.filter((s) => s !== term)].slice(0, 8);
    setRecentSearches(updated);
    await AsyncStorage.setItem(RECENT_KEY, JSON.stringify(updated));
  }, [recentSearches]);

  const clearRecent = useCallback(async () => {
    setRecentSearches([]);
    await AsyncStorage.removeItem(RECENT_KEY);
  }, []);

  const handleSearch = useCallback((term: string) => {
    if (term.trim()) {
      saveSearch(term.trim());
      setQuery(term.trim());
    }
  }, [saveSearch]);

  const results = searchData?.data ?? [];
  const showResults = debouncedQuery.length > 1;

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {/* Search header */}
      <View
        style={[
          styles.searchHeader,
          {
            paddingTop: insets.top + WEB_TOP + 12,
            backgroundColor: colors.background,
            borderBottomColor: colors.border,
          },
        ]}
      >
        <View
          style={[
            styles.inputRow,
            {
              backgroundColor: colors.input,
              borderColor: focused ? colors.primary : colors.border,
              borderRadius: colors.radius,
            },
          ]}
        >
          <Ionicons name="search-outline" size={18} color={colors.mutedForeground} />
          <TextInput
            ref={inputRef}
            style={[styles.input, { color: colors.foreground }]}
            placeholder="Search groceries..."
            placeholderTextColor={colors.mutedForeground}
            value={query}
            onChangeText={setQuery}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            onSubmitEditing={() => handleSearch(query)}
            returnKeyType="search"
            autoCapitalize="none"
            autoCorrect={false}
          />
          {query.length > 0 && (
            <Pressable onPress={() => setQuery('')} hitSlop={8}>
              <Ionicons name="close-circle" size={18} color={colors.mutedForeground} />
            </Pressable>
          )}
        </View>
      </View>

      {showResults ? (
        /* Results */
        <FlatList
          data={results}
          keyExtractor={(p) => String(p.id)}
          numColumns={2}
          columnWrapperStyle={styles.columnWrapper}
          contentContainerStyle={{
            padding: 16,
            paddingBottom: insets.bottom + WEB_BOTTOM + 24,
          }}
          ListHeaderComponent={
            searching ? null : (
              <Text style={[styles.resultCount, { color: colors.mutedForeground }]}>
                {results.length} results for "{debouncedQuery}"
              </Text>
            )
          }
          ListEmptyComponent={
            searching ? (
              <View style={styles.grid}>
                {[1, 2, 3, 4].map((i) => <ProductCardSkeleton key={i} />)}
              </View>
            ) : (
              <View style={styles.empty}>
                <Ionicons name="search-outline" size={48} color={colors.mutedForeground} />
                <Text style={[styles.emptyTitle, { color: colors.foreground }]}>No results found</Text>
                <Text style={[styles.emptySubtitle, { color: colors.mutedForeground }]}>
                  Try a different search term
                </Text>
              </View>
            )
          }
          renderItem={({ item }) => (
            <View style={{ flex: 1, marginHorizontal: 4 }}>
              <ProductCard product={item} />
            </View>
          )}
        />
      ) : (
        <ScrollView
          contentContainerStyle={{
            padding: 20,
            paddingBottom: insets.bottom + WEB_BOTTOM + 24,
          }}
          showsVerticalScrollIndicator={false}
        >
          {/* Recent searches */}
          {recentSearches.length > 0 && (
            <View style={styles.section}>
              <View style={styles.sectionHeader}>
                <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Recent</Text>
                <Pressable onPress={clearRecent}>
                  <Text style={[styles.clearBtn, { color: colors.primary }]}>Clear all</Text>
                </Pressable>
              </View>
              {recentSearches.map((s) => (
                <Pressable
                  key={s}
                  style={[styles.recentItem, { borderBottomColor: colors.border }]}
                  onPress={() => handleSearch(s)}
                >
                  <Ionicons name="time-outline" size={16} color={colors.mutedForeground} />
                  <Text style={[styles.recentText, { color: colors.foreground }]}>{s}</Text>
                  <Ionicons name="arrow-back-outline" size={14} color={colors.mutedForeground} />
                </Pressable>
              ))}
            </View>
          )}

          {/* Trending */}
          <View style={styles.section}>
            <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Trending</Text>
            <View style={styles.chips}>
              {TRENDING.map((t) => (
                <Pressable
                  key={t}
                  style={[styles.chip, { backgroundColor: colors.secondary, borderRadius: 20 }]}
                  onPress={() => handleSearch(t)}
                >
                  <Text style={[styles.chipText, { color: colors.secondaryForeground }]}>{t}</Text>
                </Pressable>
              ))}
            </View>
          </View>

          {/* Categories */}
          <View style={styles.section}>
            <Text style={[styles.sectionTitle, { color: colors.foreground }]}>Browse by Category</Text>
            <View style={styles.catGrid}>
              {(categories ?? []).map((cat) => (
                <Pressable
                  key={cat.id}
                  style={[
                    styles.catChip,
                    {
                      backgroundColor: colors.card,
                      borderColor: colors.border,
                      borderRadius: colors.radius,
                    },
                  ]}
                  onPress={() => router.push(`/category/${cat.id}`)}
                >
                  <Text style={[styles.catText, { color: colors.foreground }]} numberOfLines={1}>
                    {cat.name}
                  </Text>
                  <Ionicons name="chevron-forward" size={14} color={colors.mutedForeground} />
                </Pressable>
              ))}
            </View>
          </View>
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  searchHeader: {
    padding: 16,
    paddingTop: 16,
    borderBottomWidth: 1,
  },
  inputRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderWidth: 1.5,
  },
  input: {
    flex: 1,
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    padding: 0,
  },
  section: { marginBottom: 24 },
  sectionHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12,
  },
  sectionTitle: {
    fontSize: 17,
    fontFamily: 'Inter_700Bold',
    marginBottom: 12,
  },
  clearBtn: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
  },
  recentItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingVertical: 12,
    borderBottomWidth: 1,
  },
  recentText: {
    flex: 1,
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
  },
  chip: {
    paddingHorizontal: 14,
    paddingVertical: 7,
  },
  chipText: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
  },
  catGrid: {
    gap: 8,
  },
  catChip: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: 14,
    borderWidth: 1,
  },
  catText: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
  },
  resultCount: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    marginBottom: 12,
  },
  columnWrapper: {
    marginBottom: 8,
  },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  empty: {
    alignItems: 'center',
    paddingTop: 60,
    gap: 8,
  },
  emptyTitle: {
    fontSize: 18,
    fontFamily: 'Inter_600SemiBold',
  },
  emptySubtitle: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
  },
});
