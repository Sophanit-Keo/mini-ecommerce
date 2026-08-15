import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { useQueryClient } from '@tanstack/react-query';
import {
  authLogin,
  authRegister,
  setAuthTokenGetter,
} from '@workspace/api-client-react';
import type { User } from '@workspace/api-client-react';

const ACCESS_KEY = '@grocerly/accessToken';
const REFRESH_KEY = '@grocerly/refreshToken';
const USER_KEY = '@grocerly/user';

interface AuthContextType {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const tokenRef = useRef<string | null>(null);
  const queryClient = useQueryClient();

  // Set up auth token getter once
  useEffect(() => {
    setAuthTokenGetter(() => tokenRef.current);
  }, []);

  // Load stored auth on mount
  useEffect(() => {
    async function load() {
      try {
        const [storedToken, storedUser] = await Promise.all([
          SecureStore.getItemAsync(ACCESS_KEY),
          AsyncStorage.getItem(USER_KEY),
        ]);
        if (storedToken && storedUser) {
          tokenRef.current = storedToken;
          setToken(storedToken);
          setUser(JSON.parse(storedUser) as User);
        }
      } catch {
        // ignore
      } finally {
        setIsLoading(false);
      }
    }
    load();
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const result = await authLogin({ email, password });
    tokenRef.current = result.accessToken;
    setToken(result.accessToken);
    setUser(result.user);
    await Promise.all([
      SecureStore.setItemAsync(ACCESS_KEY, result.accessToken),
      SecureStore.setItemAsync(REFRESH_KEY, result.refreshToken),
      AsyncStorage.setItem(USER_KEY, JSON.stringify(result.user)),
      // Clear plaintext credentials left by older app versions after a successful sign-in.
      AsyncStorage.removeItem(ACCESS_KEY),
      AsyncStorage.removeItem(REFRESH_KEY),
    ]);
  }, []);

  const register = useCallback(
    async (name: string, email: string, password: string) => {
      const result = await authRegister({ name, email, password });
      tokenRef.current = result.accessToken;
      setToken(result.accessToken);
      setUser(result.user);
      await Promise.all([
        AsyncStorage.setItem(ACCESS_KEY, result.accessToken),
        AsyncStorage.setItem(REFRESH_KEY, result.refreshToken),
        AsyncStorage.setItem(USER_KEY, JSON.stringify(result.user)),
      ]);
    },
    [],
  );

  const logout = useCallback(async () => {
    tokenRef.current = null;
    setToken(null);
    setUser(null);
    // Without this the next user to sign in on this device sees the previous
    // user's cached cart, orders and notifications.
    queryClient.clear();
    await Promise.all([
      SecureStore.deleteItemAsync(ACCESS_KEY),
      SecureStore.deleteItemAsync(REFRESH_KEY),
      AsyncStorage.removeItem(USER_KEY),
      // Defensive migration cleanup for any plaintext credentials created by older app builds.
      AsyncStorage.removeItem(ACCESS_KEY),
      AsyncStorage.removeItem(REFRESH_KEY),
    ]);
  }, [queryClient]);

  const value = useMemo(
    () => ({ user, token, isLoading, login, register, logout }),
    [user, token, isLoading, login, register, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
