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

// Expo bundles require an absolute API URL. The old EXPO_PUBLIC_DOMAIN preview
// value pointed to an endpoint that returns 404, so deployments must provide this
// explicit setting instead (for example, https://api.example.com or a LAN URL in dev).
const apiBaseUrl = process.env.EXPO_PUBLIC_API_BASE_URL;
if (apiBaseUrl) {
  setBaseUrl(apiBaseUrl);
} else {
  console.warn('EXPO_PUBLIC_API_BASE_URL is not configured; API requests will remain disabled.');
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
