import React, { useEffect } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { KeyboardProvider } from 'react-native-keyboard-controller';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
  useFonts,
} from '@expo-google-fonts/inter';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { setBaseUrl } from '@workspace/api-client-react';
import { AuthProvider } from '@/context/AuthContext';
import { CartProvider } from '@/context/CartContext';
import { WishlistProvider } from '@/context/WishlistContext';
import { NotificationsProvider } from '@/context/NotificationsContext';
import { ToastProvider } from '@/components/Toast';

// Set API base URL — Expo bundles need absolute URLs to reach the backend.
// A bare hostname (assumed https) or a full http://ip:port URL for local LAN
// dev both work — only default to https when no scheme is already present.
if (process.env.EXPO_PUBLIC_DOMAIN) {
  const domain = process.env.EXPO_PUBLIC_DOMAIN;
  setBaseUrl(/^https?:\/\//.test(domain) ? domain : `https://${domain}`);
}

// Prevent the splash screen from auto-hiding before asset loading is complete.
SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient();

function RootLayoutNav() {
  return (
    <Stack screenOptions={{ headerBackTitle: 'Back' }}>
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen
        name="(auth)"
        options={{ presentation: 'modal', headerShown: false }}
      />
      <Stack.Screen
        name="product/[id]"
        options={{ headerShown: false, presentation: 'card' }}
      />
      <Stack.Screen
        name="category/[id]"
        options={{ headerShown: false }}
      />
      <Stack.Screen
        name="checkout/index"
        options={{ headerShown: false, presentation: 'modal' }}
      />
      <Stack.Screen
        name="checkout/success"
        options={{ headerShown: false, gestureEnabled: false }}
      />
      <Stack.Screen
        name="order/[id]"
        options={{ headerShown: false }}
      />
      <Stack.Screen
        name="invoice/[id]"
        options={{ headerShown: false }}
      />
      <Stack.Screen
        name="wishlist"
        options={{ headerShown: false }}
      />
      <Stack.Screen
        name="notifications"
        options={{ headerShown: false }}
      />
    </Stack>
  );
}

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  });

  useEffect(() => {
    if (fontsLoaded || fontError) {
      SplashScreen.hideAsync();
    }
  }, [fontsLoaded, fontError]);

  if (!fontsLoaded && !fontError) return null;

  return (
    <SafeAreaProvider>
      <ErrorBoundary>
        <QueryClientProvider client={queryClient}>
          <GestureHandlerRootView>
            <KeyboardProvider>
              <AuthProvider>
                <CartProvider>
                  <WishlistProvider>
                    <ToastProvider>
                      <NotificationsProvider>
                        <RootLayoutNav />
                      </NotificationsProvider>
                    </ToastProvider>
                  </WishlistProvider>
                </CartProvider>
              </AuthProvider>
            </KeyboardProvider>
          </GestureHandlerRootView>
        </QueryClientProvider>
      </ErrorBoundary>
    </SafeAreaProvider>
  );
}
