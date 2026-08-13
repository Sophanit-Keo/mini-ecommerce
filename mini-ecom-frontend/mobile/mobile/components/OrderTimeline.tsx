import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useColors } from '@/hooks/useColors';
import { HALTED_STATUSES, ORDER_LADDER, STATUS_COLOR, STATUS_LABEL } from '@/constants/orderStatus';

const STATUS_ICONS: Record<string, string> = {
  pending: 'time-outline',
  confirmed: 'checkmark-circle-outline',
  preparing: 'restaurant-outline',
  ready: 'bag-check-outline',
  out_for_delivery: 'bicycle-outline',
  delivered: 'checkmark-done-circle',
  completed: 'trophy-outline',
  cancelled: 'close-circle-outline',
  rejected: 'close-circle-outline',
};

const LADDER_LABELS: Record<string, string> = {
  pending: 'Order Placed',
  confirmed: 'Confirmed',
  preparing: 'Preparing',
  ready: 'Ready for Pickup',
  out_for_delivery: 'On the Way',
  delivered: 'Delivered',
  completed: 'Completed',
};

interface TimelineEntry {
  id: number;
  status: string;
  description: string;
  timestamp: string;
}

interface OrderTimelineProps {
  timeline: TimelineEntry[];
  currentStatus: string;
}

export function OrderTimeline({ timeline, currentStatus }: OrderTimelineProps) {
  const colors = useColors();

  const isHalted = HALTED_STATUSES.includes(currentStatus);

  if (isHalted) {
    const haltColor = STATUS_COLOR[currentStatus] ?? colors.destructive;
    const haltLabel = STATUS_LABEL[currentStatus] ?? currentStatus;
    // Surface the seller's note (e.g. a rejection reason) if the server sent one.
    const lastEntry = timeline[timeline.length - 1];

    return (
      <View style={[styles.container, { backgroundColor: colors.card, borderRadius: colors.radius }]}>
        <Text style={[styles.header, { color: colors.foreground }]}>Order Timeline</Text>
        <View style={[styles.cancelBadge, { backgroundColor: haltColor + '20', borderRadius: 8 }]}>
          <Ionicons name="close-circle" size={20} color={haltColor} />
          <Text style={[styles.cancelText, { color: haltColor }]}>Order {haltLabel}</Text>
        </View>
        {lastEntry?.description && (
          <Text style={[styles.haltReason, { color: colors.mutedForeground }]}>
            {lastEntry.description}
          </Text>
        )}
      </View>
    );
  }

  const currentIdx = ORDER_LADDER.indexOf(currentStatus);

  return (
    <View style={[styles.container, { backgroundColor: colors.card, borderRadius: colors.radius }]}>
      <Text style={[styles.header, { color: colors.foreground }]}>Order Timeline</Text>
      {ORDER_LADDER.map((key, idx) => {
        const isDone = idx <= currentIdx;
        const isCurrent = idx === currentIdx;
        const entry = timeline.find((t) => t.status === key);

        return (
          <View key={key} style={styles.row}>
            <View style={styles.iconCol}>
              <View
                style={[
                  styles.iconCircle,
                  {
                    backgroundColor: isDone ? colors.primary : colors.muted,
                    borderColor: isCurrent ? colors.primary : 'transparent',
                    borderWidth: isCurrent ? 2 : 0,
                  },
                ]}
              >
                <Ionicons
                  name={(STATUS_ICONS[key] ?? 'ellipse-outline') as any}
                  size={14}
                  color={isDone ? '#FFF' : colors.mutedForeground}
                />
              </View>
              {idx < ORDER_LADDER.length - 1 && (
                <View
                  style={[
                    styles.line,
                    { backgroundColor: idx < currentIdx ? colors.primary : colors.border },
                  ]}
                />
              )}
            </View>
            <View style={styles.textCol}>
              <Text
                style={[
                  styles.statusLabel,
                  {
                    color: isDone ? colors.foreground : colors.mutedForeground,
                    fontFamily: isCurrent ? 'Inter_600SemiBold' : 'Inter_400Regular',
                  },
                ]}
              >
                {LADDER_LABELS[key] ?? STATUS_LABEL[key] ?? key}
              </Text>
              {entry && (
                <Text style={[styles.timestamp, { color: colors.mutedForeground }]}>
                  {new Date(entry.timestamp).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                  })}
                </Text>
              )}
            </View>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    padding: 16,
    gap: 4,
  },
  header: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
    marginBottom: 12,
  },
  row: {
    flexDirection: 'row',
    gap: 12,
  },
  iconCol: {
    alignItems: 'center',
    width: 32,
  },
  iconCircle: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  line: {
    width: 2,
    height: 24,
    borderRadius: 1,
    marginVertical: 2,
  },
  textCol: {
    flex: 1,
    paddingTop: 6,
    paddingBottom: 16,
    gap: 2,
  },
  statusLabel: {
    fontSize: 14,
  },
  timestamp: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
  },
  cancelBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    gap: 8,
    marginTop: 4,
  },
  cancelText: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
  haltReason: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    marginTop: 8,
    lineHeight: 18,
  },
});
