/**
 * Lightweight global toast overlay.
 *
 * Usage:
 *   1. Render <ToastProvider> once near the root (inside SafeAreaProvider).
 *   2. Call `useToast().show({ message, type?, action? })` from any screen.
 */
import React, {
  createContext,
  useCallback,
  useContext,
  useRef,
  useState,
} from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useColors } from '@/hooks/useColors';

export type ToastType = 'error' | 'success' | 'info';

export interface ToastAction {
  label: string;
  onPress: () => void;
}

export interface ToastOptions {
  message: string;
  type?: ToastType;
  duration?: number; // ms, default 4000
  action?: ToastAction;
}

interface ToastContextValue {
  show: (options: ToastOptions) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const ICON: Record<ToastType, React.ComponentProps<typeof Ionicons>['name']> = {
  error: 'alert-circle',
  success: 'checkmark-circle',
  info: 'information-circle',
};

function ToastBanner({
  visible,
  options,
  translateY,
}: {
  visible: boolean;
  options: ToastOptions | null;
  translateY: Animated.Value;
}) {
  const colors = useColors();
  const insets = useSafeAreaInsets();

  if (!options) return null;

  const type = options.type ?? 'info';

  const bg: Record<ToastType, string> = {
    error: colors.destructive,
    success: colors.primary,
    info: colors.foreground,
  };

  return (
    <Animated.View
      pointerEvents={visible ? 'box-none' : 'none'}
      style={[
        styles.container,
        {
          bottom: insets.bottom + 16,
          transform: [{ translateY }],
        },
      ]}
    >
      <View
        style={[
          styles.toast,
          { backgroundColor: bg[type], borderRadius: colors.radius },
        ]}
      >
        <Ionicons name={ICON[type]} size={20} color="#FFF" style={styles.icon} />
        <Text style={styles.message} numberOfLines={3}>
          {options.message}
        </Text>
        {options.action && (
          <Pressable onPress={options.action.onPress} hitSlop={8}>
            <Text style={styles.action}>{options.action.label}</Text>
          </Pressable>
        )}
      </View>
    </Animated.View>
  );
}

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [current, setCurrent] = useState<ToastOptions | null>(null);
  const [visible, setVisible] = useState(false);
  const translateY = useRef(new Animated.Value(100)).current;
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const dismiss = useCallback(() => {
    Animated.timing(translateY, {
      toValue: 100,
      duration: 250,
      useNativeDriver: true,
    }).start(() => {
      setVisible(false);
      setCurrent(null);
    });
  }, [translateY]);

  const show = useCallback(
    (options: ToastOptions) => {
      if (timerRef.current) clearTimeout(timerRef.current);

      setCurrent(options);
      setVisible(true);

      // Slide up
      translateY.setValue(100);
      Animated.spring(translateY, {
        toValue: 0,
        useNativeDriver: true,
        tension: 80,
        friction: 12,
      }).start();

      // Auto-dismiss
      timerRef.current = setTimeout(dismiss, options.duration ?? 4000);
    },
    [translateY, dismiss],
  );

  return (
    <ToastContext.Provider value={{ show }}>
      {children}
      <ToastBanner visible={visible} options={current} translateY={translateY} />
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be inside ToastProvider');
  return ctx;
}

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    left: 16,
    right: 16,
    zIndex: 9999,
  },
  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 14,
    paddingVertical: 12,
    gap: 10,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.18,
    shadowRadius: 8,
    elevation: 8,
  },
  icon: {
    flexShrink: 0,
  },
  message: {
    flex: 1,
    color: '#FFF',
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
    lineHeight: 20,
  },
  action: {
    color: '#FFF',
    fontSize: 13,
    fontFamily: 'Inter_700Bold',
    textDecorationLine: 'underline',
    flexShrink: 0,
  },
});
