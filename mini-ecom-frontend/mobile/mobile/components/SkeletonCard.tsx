import React, { useEffect, useRef } from 'react';
import { Animated, StyleSheet, View } from 'react-native';
import { useColors } from '@/hooks/useColors';

function SkeletonBox({
  width,
  height,
  borderRadius,
  style,
}: {
  width: number | string;
  height: number;
  borderRadius?: number;
  style?: object;
}) {
  const colors = useColors();
  const anim = useRef(new Animated.Value(0.4)).current;

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(anim, {
          toValue: 1,
          duration: 800,
          useNativeDriver: true,
        }),
        Animated.timing(anim, {
          toValue: 0.4,
          duration: 800,
          useNativeDriver: true,
        }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [anim]);

  return (
    <Animated.View
      style={[
        {
          width: width as number,
          height,
          borderRadius: borderRadius ?? 6,
          backgroundColor: colors.muted,
          opacity: anim,
        },
        style,
      ]}
    />
  );
}

export function ProductCardSkeleton() {
  const colors = useColors();
  return (
    <View
      style={[
        styles.card,
        {
          backgroundColor: colors.card,
          borderColor: colors.border,
          borderRadius: colors.radius,
        },
      ]}
    >
      <SkeletonBox width="100%" height={130} borderRadius={0} />
      <View style={styles.info}>
        <SkeletonBox width="80%" height={13} />
        <SkeletonBox width="50%" height={11} />
        <View style={styles.priceRow}>
          <SkeletonBox width={60} height={20} />
          <SkeletonBox width={32} height={32} borderRadius={8} />
        </View>
      </View>
    </View>
  );
}

export function CategoryCardSkeleton() {
  const colors = useColors();
  return (
    <View style={[styles.catCard, { backgroundColor: colors.muted, borderRadius: colors.radius }]}>
      <SkeletonBox width={44} height={44} borderRadius={22} />
      <SkeletonBox width={60} height={11} />
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    overflow: 'hidden',
    width: 148,
    marginRight: 12,
  },
  info: {
    padding: 10,
    gap: 6,
  },
  priceRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 4,
  },
  catCard: {
    width: 80,
    alignItems: 'center',
    padding: 8,
    gap: 6,
    marginRight: 8,
  },
});
