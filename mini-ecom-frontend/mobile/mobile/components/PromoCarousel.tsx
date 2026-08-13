import React, { useRef, useState } from 'react';
import {
  Dimensions,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useColors } from '@/hooks/useColors';

const { width: SCREEN_WIDTH } = Dimensions.get('window');
const CARD_WIDTH = SCREEN_WIDTH - 48;

interface PromoSlide {
  id: string;
  title: string;
  subtitle: string;
  tag: string;
  gradient: [string, string];
  cta: string;
}

const SLIDES: PromoSlide[] = [
  {
    id: '1',
    title: 'Fresh Picks\nDelivered Fast',
    subtitle: 'Get groceries in under 60 minutes',
    tag: '🌿 Same-day delivery',
    gradient: ['#22C55E', '#16A34A'],
    cta: 'Shop Now',
  },
  {
    id: '2',
    title: 'First Order\n10% Off',
    subtitle: 'Use code FRESH10 at checkout',
    tag: '🏷️ Promo code',
    gradient: ['#F97316', '#EA580C'],
    cta: 'Claim Deal',
  },
  {
    id: '3',
    title: 'Flash Sale\nEvery Day',
    subtitle: 'Up to 30% off on select items',
    tag: '⚡ Limited time',
    gradient: ['#3B82F6', '#2563EB'],
    cta: 'View Sale',
  },
];

export function PromoCarousel() {
  const colors = useColors();
  const [activeIndex, setActiveIndex] = useState(0);
  const flatRef = useRef<FlatList>(null);

  return (
    <View>
      <FlatList
        ref={flatRef}
        data={SLIDES}
        keyExtractor={(item) => item.id}
        horizontal
        pagingEnabled={false}
        snapToInterval={CARD_WIDTH + 12}
        decelerationRate="fast"
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={{ paddingHorizontal: 24, gap: 12 }}
        onMomentumScrollEnd={(e) => {
          const idx = Math.round(
            e.nativeEvent.contentOffset.x / (CARD_WIDTH + 12),
          );
          setActiveIndex(idx);
        }}
        renderItem={({ item }) => (
          <Pressable style={[styles.slide, { width: CARD_WIDTH, borderRadius: colors.radius + 4 }]}>
            <View
              style={[
                styles.slideInner,
                { borderRadius: colors.radius + 4, backgroundColor: item.gradient[0] },
              ]}
            >
              <View style={[styles.accent, { backgroundColor: item.gradient[1] }]} />
              <View style={styles.content}>
                <View style={styles.tag}>
                  <Text style={styles.tagText}>{item.tag}</Text>
                </View>
                <Text style={styles.title}>{item.title}</Text>
                <Text style={styles.subtitle}>{item.subtitle}</Text>
                <View style={styles.ctaBtn}>
                  <Text style={styles.ctaText}>{item.cta} →</Text>
                </View>
              </View>
            </View>
          </Pressable>
        )}
      />
      <View style={styles.dots}>
        {SLIDES.map((_, i) => (
          <View
            key={i}
            style={[
              styles.dot,
              {
                backgroundColor:
                  i === activeIndex ? colors.primary : colors.border,
                width: i === activeIndex ? 16 : 6,
              },
            ]}
          />
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  slide: {
    height: 160,
    overflow: 'hidden',
  },
  slideInner: {
    flex: 1,
    overflow: 'hidden',
  },
  accent: {
    position: 'absolute',
    width: 180,
    height: 180,
    borderRadius: 90,
    right: -40,
    bottom: -60,
    opacity: 0.35,
  },
  content: {
    flex: 1,
    padding: 20,
    gap: 6,
    justifyContent: 'center',
  },
  tag: {
    backgroundColor: 'rgba(255,255,255,0.25)',
    alignSelf: 'flex-start',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 20,
    marginBottom: 4,
  },
  tagText: {
    fontSize: 11,
    color: '#FFFFFF',
    fontFamily: 'Inter_500Medium',
  },
  title: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
    color: '#FFFFFF',
    lineHeight: 28,
  },
  subtitle: {
    fontSize: 13,
    color: 'rgba(255,255,255,0.85)',
    fontFamily: 'Inter_400Regular',
  },
  ctaBtn: {
    marginTop: 8,
    backgroundColor: 'rgba(255,255,255,0.25)',
    alignSelf: 'flex-start',
    paddingHorizontal: 14,
    paddingVertical: 6,
    borderRadius: 20,
  },
  ctaText: {
    fontSize: 13,
    fontFamily: 'Inter_600SemiBold',
    color: '#FFFFFF',
  },
  dots: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    marginTop: 12,
  },
  dot: {
    height: 6,
    borderRadius: 3,
  },
});
