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
  productIds: number[];
  isInWishlist: (productId: number) => boolean;
  toggle: (productId: number) => void;
}

const WishlistContext = createContext<WishlistContextType | null>(null);

export function WishlistProvider({ children }: { children: React.ReactNode }) {
  const [productIds, setProductIds] = useState<number[]>([]);

  useEffect(() => {
    AsyncStorage.getItem(WISHLIST_KEY).then((raw) => {
      if (raw) setProductIds(JSON.parse(raw) as number[]);
    });
  }, []);

  const persist = useCallback((ids: number[]) => {
    setProductIds(ids);
    AsyncStorage.setItem(WISHLIST_KEY, JSON.stringify(ids));
  }, []);

  const isInWishlist = useCallback(
    (productId: number) => productIds.includes(productId),
    [productIds],
  );

  const toggle = useCallback(
    (productId: number) => {
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
