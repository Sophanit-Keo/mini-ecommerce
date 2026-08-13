import React from 'react';
import { Image, Pressable, StyleSheet, Text } from 'react-native';
import { router } from 'expo-router';
import { useColors } from '@/hooks/useColors';
import type { Category } from '@workspace/api-client-react';

interface CategoryCardProps {
  category: Category;
  size?: 'small' | 'large';
}

export function CategoryCard({ category, size = 'small' }: CategoryCardProps) {
  const colors = useColors();

  if (size === 'large') {
    return (
      <Pressable
        style={({ pressed }) => [
          styles.largeCard,
          {
            backgroundColor: colors.card,
            borderColor: colors.border,
            borderRadius: colors.radius,
            opacity: pressed ? 0.9 : 1,
          },
        ]}
        onPress={() => router.push(`/category/${category.id}`)}
      >
        <Image
          source={{ uri: category.image }}
          style={[styles.largeImage, { borderRadius: colors.radius - 2 }]}
          resizeMode="cover"
        />
        <Text
          style={[styles.largeName, { color: colors.foreground }]}
          numberOfLines={2}
        >
          {category.name}
        </Text>
        <Text style={[styles.count, { color: colors.mutedForeground }]}>
          {category.productCount} items
        </Text>
      </Pressable>
    );
  }

  return (
    <Pressable
      style={({ pressed }) => [
        styles.smallCard,
        {
          backgroundColor: colors.secondary,
          borderRadius: colors.radius,
          opacity: pressed ? 0.85 : 1,
        },
      ]}
      onPress={() => router.push(`/category/${category.id}`)}
    >
      <Image
        source={{ uri: category.image }}
        style={styles.smallImage}
        resizeMode="cover"
      />
      <Text
        style={[styles.smallName, { color: colors.secondaryForeground }]}
        numberOfLines={1}
      >
        {category.name}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  smallCard: {
    width: 80,
    alignItems: 'center',
    padding: 8,
    gap: 6,
    marginRight: 8,
  },
  smallImage: {
    width: 44,
    height: 44,
    borderRadius: 22,
  },
  smallName: {
    fontSize: 11,
    fontFamily: 'Inter_500Medium',
    textAlign: 'center',
  },
  largeCard: {
    width: 140,
    borderWidth: 1,
    overflow: 'hidden',
    marginRight: 12,
  },
  largeImage: {
    width: '100%',
    height: 90,
  },
  largeName: {
    fontSize: 13,
    fontFamily: 'Inter_600SemiBold',
    paddingHorizontal: 10,
    paddingTop: 8,
  },
  count: {
    fontSize: 11,
    fontFamily: 'Inter_400Regular',
    paddingHorizontal: 10,
    paddingBottom: 10,
  },
});
