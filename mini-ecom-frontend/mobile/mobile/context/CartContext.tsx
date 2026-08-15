import React, {
  createContext,
  useCallback,
  useContext,
  useMemo,
} from 'react';
import { Alert } from 'react-native';
import { router } from 'expo-router';
import { useQueryClient } from '@tanstack/react-query';
import {
  useListCart,
  useAddCartItem,
  useUpdateCartItem,
  useRemoveCartItem,
  useClearCart as useClearCartMutation,
  getListCartQueryKey,
} from '@workspace/api-client-react';
import { useAuth } from '@/context/AuthContext';

/**
 * Cart item shape consumed by mobile screens/components. Backed by the
 * server-side `/v1/cart` API (cart_items table) rather than AsyncStorage —
 * `id` is the cart_items row id, needed for update/remove calls.
 */
export interface CartItem {
  id: string;
  productId: string;
  name: string;
  price: number;
  quantity: number;
  unit: string;
  image: string;
}

/**
 * Input accepted by `addItem`. Only `productId`/`quantity` are actually sent
 * to the server — the rest are accepted (and ignored) so existing call sites
 * that pass product details (name/price/unit/image) don't need to change.
 */
interface AddItemInput {
  productId: string;
  quantity?: number;
  name?: string;
  price?: number;
  unit?: string;
  image?: string;
}

interface CartContextType {
  items: CartItem[];
  isLoading: boolean;
  addItem: (item: AddItemInput) => void;
  removeItem: (productId: string) => void;
  updateQuantity: (productId: string, quantity: number) => void;
  clearCart: () => void;
  totalItems: number;
  subtotal: number;
}

const CartContext = createContext<CartContextType | null>(null);

export function CartProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const queryClient = useQueryClient();

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const { data, isLoading } = useListCart({
    query: { enabled: !!user } as any,
  });

  const invalidate = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: getListCartQueryKey() });
  }, [queryClient]);

  const addMutation = useAddCartItem({ mutation: { onSuccess: invalidate } });
  const updateMutation = useUpdateCartItem({ mutation: { onSuccess: invalidate } });
  const removeMutation = useRemoveCartItem({ mutation: { onSuccess: invalidate } });
  const clearMutation = useClearCartMutation({ mutation: { onSuccess: invalidate } });

  const items: CartItem[] = useMemo(
    () =>
      (data ?? []).map((ci) => ({
        id: ci.id,
        productId: ci.productId,
        name: ci.product.name,
        price: ci.product.price,
        quantity: ci.quantity,
        unit: ci.product.unit,
        image: ci.product.image,
      })),
    [data],
  );

  // The cart API requires auth. Rather than crashing for logged-out users
  // (who can still browse products), prompt them to sign in.
  const requireAuth = useCallback(() => {
    if (user) return true;
    Alert.alert(
      'Sign in required',
      'Please sign in to add items to your cart.',
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Sign In', onPress: () => router.push('/(auth)/login') },
      ],
    );
    return false;
  }, [user],
  );

  const addItem = useCallback(
    (item: AddItemInput) => {
      if (!requireAuth()) return;
      addMutation.mutate({
        data: { productId: item.productId, quantity: item.quantity ?? 1 },
      });
    },
    [requireAuth, addMutation],
  );

  const removeItem = useCallback(
    (productId: string) => {
      if (!requireAuth()) return;
      const item = items.find((i) => i.productId === productId);
      if (!item) return;
      removeMutation.mutate({ id: item.id });
    },
    [requireAuth, items, removeMutation],
  );

  const updateQuantity = useCallback(
    (productId: string, quantity: number) => {
      if (!requireAuth()) return;
      const item = items.find((i) => i.productId === productId);
      if (!item) return;
      if (quantity <= 0) {
        removeMutation.mutate({ id: item.id });
        return;
      }
      updateMutation.mutate({ id: item.id, data: { quantity } });
    },
    [requireAuth, items, updateMutation, removeMutation],
  );

  const clearCart = useCallback(() => {
    if (!user) return;
    clearMutation.mutate();
  }, [user, clearMutation]);

  const totalItems = useMemo(
    () => items.reduce((sum, i) => sum + i.quantity, 0),
    [items],
  );

  const subtotal = useMemo(
    () => items.reduce((sum, i) => sum + i.price * i.quantity, 0),
    [items],
  );

  const value = useMemo(
    () => ({
      items,
      isLoading,
      addItem,
      removeItem,
      updateQuantity,
      clearCart,
      totalItems,
      subtotal,
    }),
    [items, isLoading, addItem, removeItem, updateQuantity, clearCart, totalItems, subtotal],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used inside CartProvider');
  return ctx;
}
