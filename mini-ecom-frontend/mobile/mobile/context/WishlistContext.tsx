import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';

const WISHLIST_KEY = '@grocerly/wishlist';

interface WishlistContextType {
  productIds: string[];
  isInWishlist: (productId: string) => boolean;
  toggle: (productId: string) => void;
}

const WishlistContext = createContext<WishlistContextType | null>(null);

export function WishlistProvider({ children }: { children: React.ReactNode }) {
  const [productIds, setProductIds] = useState<string[]>([]);

  useEffect(() => {
    AsyncStorage.getItem(WISHLIST_KEY).then((raw) => {
      if (raw) setProductIds((JSON.parse(raw) as unknown[]).map(String));
    });
  }, []);

  const persist = useCallback((ids: string[]) => {
    setProductIds(ids);
    AsyncStorage.setItem(WISHLIST_KEY, JSON.stringify(ids));
  }, []);

  const isInWishlist = useCallback(
    (productId: string) => productIds.includes(productId),
    [productIds],
  );

  const toggle = useCallback(
    (productId: string) => {
      const next = productIds.includes(productId)
        ? productIds.filter((id) => id !== productId)
        : [...productIds, productId];
      persist(next);
    },
    [productIds, persist],
  );

  const value = useMemo(
    () => ({ productIds, isInWishlist, toggle }),
    [productIds, isInWishlist, toggle],
  );

  return (
    <WishlistContext.Provider value={value}>
      {children}
    </WishlistContext.Provider>
  );
}

export function useWishlist() {
  const ctx = useContext(WishlistContext);
  if (!ctx) throw new Error('useWishlist must be used inside WishlistProvider');
  return ctx;
}
