import React, { useEffect, useRef } from 'react';
import { Animated, Easing, StyleSheet, Text, View } from 'react-native';
import Svg, { Circle, Defs, G, Line, LinearGradient, Path, Rect, Stop } from 'react-native-svg';
import { Ionicons } from '@expo/vector-icons';
import { useColors } from '@/hooks/useColors';

// Simulated driver waypoints (lat-like normalized 0..1 values for SVG)
const WAYPOINTS = [
  { x: 0.15, y: 0.75 },
  { x: 0.25, y: 0.55 },
  { x: 0.40, y: 0.45 },
  { x: 0.55, y: 0.38 },
  { x: 0.68, y: 0.30 },
  { x: 0.80, y: 0.22 }, // destination
];

function lerp(a: number, b: number, t: number) {
  return a + (b - a) * t;
}

function interpolateAlongRoute(progress: number) {
  const totalSegments = WAYPOINTS.length - 1;
  const segment = Math.min(Math.floor(progress * totalSegments), totalSegments - 1);
  const segProgress = progress * totalSegments - segment;
  const from = WAYPOINTS[segment]!;
  const to = WAYPOINTS[segment + 1]!;
  return { x: lerp(from.x, to.x, segProgress), y: lerp(from.y, to.y, segProgress) };
}

interface DeliveryMapProps {
  status: string;
  address?: { street: string; city: string } | null;
}

export function DeliveryMap({ status, address }: DeliveryMapProps) {
  const colors = useColors();
  const progressAnim = useRef(new Animated.Value(0)).current;
  const pulseAnim = useRef(new Animated.Value(1)).current;
  const [driverPos, setDriverPos] = React.useState(WAYPOINTS[0]!);

  const isActive = status === 'out_for_delivery';
  const isDelivered = status === 'delivered';
  const isCancelled = status === 'cancelled';

  useEffect(() => {
    if (isActive) {
      // Animate driver along route over ~60 seconds (simulated)
      Animated.timing(progressAnim, {
        toValue: 0.85,
        duration: 60000,
        easing: Easing.linear,
        useNativeDriver: false,
      }).start();
    } else if (isDelivered) {
      progressAnim.setValue(1);
    }

    const listener = progressAnim.addListener(({ value }) => {
      setDriverPos(interpolateAlongRoute(value));
    });
    return () => progressAnim.removeListener(listener);
  }, [status]);

  useEffect(() => {
    // Pulse animation for driver pin
    Animated.loop(
      Animated.sequence([
        Animated.timing(pulseAnim, { toValue: 1.4, duration: 800, useNativeDriver: true }),
        Animated.timing(pulseAnim, { toValue: 1, duration: 800, useNativeDriver: true }),
      ])
    ).start();
  }, []);

  const WIDTH = 340;
  const HEIGHT = 160;

  const px = driverPos.x * WIDTH;
  const py = driverPos.y * HEIGHT;
  const destX = WAYPOINTS[WAYPOINTS.length - 1]!.x * WIDTH;
  const destY = WAYPOINTS[WAYPOINTS.length - 1]!.y * HEIGHT;

  const routePath = WAYPOINTS.map((p, i) =>
    `${i === 0 ? 'M' : 'L'} ${p.x * WIDTH} ${p.y * HEIGHT}`
  ).join(' ');

  return (
    <View style={[styles.container, { backgroundColor: colors.card, borderRadius: colors.radius, borderColor: colors.border }]}>
      {/* Map SVG */}
      <Svg width="100%" height={HEIGHT} viewBox={`0 0 ${WIDTH} ${HEIGHT}`}>
        <Defs>
          <LinearGradient id="mapBg" x1="0" y1="0" x2="0" y2="1">
            <Stop offset="0" stopColor={colors.muted} stopOpacity="0.5" />
            <Stop offset="1" stopColor={colors.muted} stopOpacity="0.8" />
          </LinearGradient>
        </Defs>

        {/* Background */}
        <Rect x="0" y="0" width={WIDTH} height={HEIGHT} fill={`url(#mapBg)`} rx="0" />

        {/* Road grid */}
        {[40, 80, 120].map(y => (
          <Line key={`h${y}`} x1="0" y1={y} x2={WIDTH} y2={y} stroke={colors.border} strokeWidth="1" opacity="0.5" />
        ))}
        {[60, 120, 180, 240, 300].map(x => (
          <Line key={`v${x}`} x1={x} y1="0" x2={x} y2={HEIGHT} stroke={colors.border} strokeWidth="1" opacity="0.5" />
        ))}

        {/* Simulated blocks */}
        {[
          { x: 10, y: 10, w: 40, h: 25 },
          { x: 70, y: 10, w: 35, h: 25 },
          { x: 130, y: 10, w: 40, h: 25 },
          { x: 200, y: 10, w: 50, h: 25 },
          { x: 10, y: 50, w: 40, h: 25 },
          { x: 130, y: 50, w: 35, h: 25 },
          { x: 250, y: 50, w: 30, h: 25 },
          { x: 10, y: 90, w: 50, h: 25 },
          { x: 80, y: 90, w: 35, h: 25 },
          { x: 200, y: 90, w: 45, h: 25 },
          { x: 10, y: 130, w: 60, h: 25 },
          { x: 90, y: 130, w: 40, h: 25 },
          { x: 250, y: 130, w: 50, h: 25 },
        ].map((b, i) => (
          <Rect key={i} x={b.x} y={b.y} width={b.w} height={b.h} rx="3" fill={colors.secondary} opacity="0.6" />
        ))}

        {/* Route path (dashed) */}
        <Path
          d={routePath}
          fill="none"
          stroke={colors.primary}
          strokeWidth="2.5"
          strokeDasharray="6 4"
          opacity="0.5"
        />

        {/* Destination pin */}
        <G>
          <Circle cx={destX} cy={destY} r="10" fill={colors.primary} opacity="0.2" />
          <Circle cx={destX} cy={destY} r="6" fill={colors.primary} />
          <Circle cx={destX} cy={destY} r="2.5" fill="white" />
        </G>

        {/* Driver pin (only show if active or delivered) */}
        {(isActive || isDelivered) && !isCancelled && (
          <G>
            <Circle cx={px} cy={py} r="12" fill={colors.primary} opacity="0.15" />
            <Circle cx={px} cy={py} r="8" fill={colors.primary} opacity="0.3" />
            <Circle cx={px} cy={py} r="5" fill="#F97316" />
            <Circle cx={px} cy={py} r="2" fill="white" />
          </G>
        )}
      </Svg>

      {/* Status overlay */}
      <View style={[styles.overlay, { backgroundColor: colors.card + 'CC' }]}>
        {isCancelled ? (
          <>
            <Ionicons name="close-circle-outline" size={18} color={colors.destructive} />
            <Text style={[styles.overlayText, { color: colors.destructive }]}>Order cancelled</Text>
          </>
        ) : isDelivered ? (
          <>
            <Ionicons name="checkmark-circle" size={18} color="#22C55E" />
            <Text style={[styles.overlayText, { color: '#22C55E' }]}>Delivered!</Text>
          </>
        ) : isActive ? (
          <>
            <Ionicons name="bicycle" size={18} color="#F97316" />
            <Text style={[styles.overlayText, { color: '#F97316' }]}>Driver on the way</Text>
          </>
        ) : (
          <>
            <Ionicons name="map-outline" size={18} color={colors.mutedForeground} />
            <Text style={[styles.overlayText, { color: colors.mutedForeground }]}>Tracking will appear when dispatched</Text>
          </>
        )}
        {address && (
          <Text style={[styles.addrText, { color: colors.foreground }]} numberOfLines={1}>
            {address.street}, {address.city}
          </Text>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    overflow: 'hidden',
    borderWidth: 1,
  },
  overlay: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingVertical: 8,
    gap: 6,
    flexWrap: 'wrap',
  },
  overlayText: {
    fontSize: 13,
    fontFamily: 'Inter_500Medium',
    flex: 1,
  },
  addrText: {
    width: '100%',
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
    marginTop: 2,
  },
});
