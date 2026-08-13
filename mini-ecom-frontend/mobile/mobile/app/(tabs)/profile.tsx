import React from 'react';
import {
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useColorScheme,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useColors } from '@/hooks/useColors';
import { useAuth } from '@/context/AuthContext';

const WEB_TOP = Platform.OS === 'web' ? 67 : 0;
const WEB_BOTTOM = Platform.OS === 'web' ? 34 + 84 : 0;

interface MenuItemProps {
  icon: string;
  label: string;
  value?: string;
  onPress: () => void;
  color?: string;
  showArrow?: boolean;
}

function MenuItem({ icon, label, value, onPress, color, showArrow = true }: MenuItemProps) {
  const colors = useColors();
  return (
    <Pressable
      style={({ pressed }) => [
        styles.menuItem,
        {
          backgroundColor: colors.card,
          borderColor: colors.border,
          opacity: pressed ? 0.85 : 1,
        },
      ]}
      onPress={onPress}
    >
      <View style={[styles.menuIcon, { backgroundColor: (color ?? colors.primary) + '15', borderRadius: 10 }]}>
        <Ionicons name={icon as any} size={20} color={color ?? colors.primary} />
      </View>
      <Text style={[styles.menuLabel, { color: colors.foreground }]} numberOfLines={1}>
        {label}
      </Text>
      <View style={styles.menuRight}>
        {value && (
          <Text style={[styles.menuValue, { color: colors.mutedForeground }]}>{value}</Text>
        )}
        {showArrow && (
          <Ionicons name="chevron-forward" size={16} color={colors.mutedForeground} />
        )}
      </View>
    </Pressable>
  );
}

export default function ProfileScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { user, logout } = useAuth();
  const scheme = useColorScheme();

  const initials = user?.name
    ? user.name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()
    : '?';

  if (!user) {
    return (
      <View
        style={[
          styles.authGate,
          {
            backgroundColor: colors.background,
            paddingTop: insets.top + WEB_TOP,
          },
        ]}
      >
        <View style={[styles.avatar, { backgroundColor: colors.secondary }]}>
          <Ionicons name="person-outline" size={40} color={colors.primary} />
        </View>
        <Text style={[styles.gateTitle, { color: colors.foreground }]}>Sign in to your account</Text>
        <Text style={[styles.gateSubtitle, { color: colors.mutedForeground }]}>
          Manage orders, wishlist, and settings
        </Text>
        <Pressable
          style={[styles.signInBtn, { backgroundColor: colors.primary, borderRadius: colors.radius }]}
          onPress={() => router.push('/(auth)/login')}
        >
          <Text style={[styles.signInText, { color: colors.primaryForeground }]}>Sign In</Text>
        </Pressable>
        <Pressable onPress={() => router.push('/(auth)/register')}>
          <Text style={[styles.registerLink, { color: colors.primary }]}>Create an account</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <ScrollView
      style={[styles.container, { backgroundColor: colors.background }]}
      contentContainerStyle={{
        paddingTop: insets.top + WEB_TOP + 16,
        paddingBottom: insets.bottom + WEB_BOTTOM + 24,
      }}
      showsVerticalScrollIndicator={false}
    >
      {/* Profile header */}
      <View style={[styles.profileHeader, { backgroundColor: colors.card, borderColor: colors.border }]}>
        <View style={[styles.avatar, { backgroundColor: colors.primary }]}>
          <Text style={styles.avatarText}>{initials}</Text>
        </View>
        <View style={styles.profileInfo}>
          <Text style={[styles.name, { color: colors.foreground }]}>{user.name}</Text>
          <Text style={[styles.email, { color: colors.mutedForeground }]}>{user.email}</Text>
        </View>
        <Pressable
          style={[styles.editBtn, { backgroundColor: colors.secondary, borderRadius: 20 }]}
          onPress={() => {}}
        >
          <Ionicons name="pencil" size={14} color={colors.primary} />
        </Pressable>
      </View>

      {/* Account section */}
      <Text style={[styles.sectionLabel, { color: colors.mutedForeground }]}>Account</Text>
      <View style={[styles.menuGroup, { borderColor: colors.border, borderRadius: colors.radius }]}>
        <MenuItem
          icon="receipt-outline"
          label="My Orders"
          onPress={() => router.push('/(tabs)/orders')}
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="heart-outline"
          label="Wishlist"
          onPress={() => router.push('/wishlist')}
          color="#EF4444"
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="location-outline"
          label="Saved Addresses"
          onPress={() => {}}
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="notifications-outline"
          label="Notifications"
          onPress={() => router.push('/notifications')}
        />
      </View>

      {/* Preferences */}
      <Text style={[styles.sectionLabel, { color: colors.mutedForeground }]}>Preferences</Text>
      <View style={[styles.menuGroup, { borderColor: colors.border, borderRadius: colors.radius }]}>
        <MenuItem
          icon="moon-outline"
          label="Appearance"
          value={scheme === 'dark' ? 'Dark' : 'Light'}
          onPress={() => {}}
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="language-outline"
          label="Language"
          value="English"
          onPress={() => {}}
        />
      </View>

      {/* Support */}
      <Text style={[styles.sectionLabel, { color: colors.mutedForeground }]}>Support</Text>
      <View style={[styles.menuGroup, { borderColor: colors.border, borderRadius: colors.radius }]}>
        <MenuItem
          icon="help-circle-outline"
          label="Help Center"
          onPress={() => {}}
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="document-text-outline"
          label="Privacy Policy"
          onPress={() => {}}
        />
        <View style={[styles.separator, { backgroundColor: colors.border }]} />
        <MenuItem
          icon="shield-checkmark-outline"
          label="Terms of Service"
          onPress={() => {}}
        />
      </View>

      {/* Logout */}
      <Pressable
        style={({ pressed }) => [
          styles.logoutBtn,
          {
            backgroundColor: colors.card,
            borderColor: colors.border,
            borderRadius: colors.radius,
            opacity: pressed ? 0.85 : 1,
          },
        ]}
        onPress={logout}
      >
        <Ionicons name="log-out-outline" size={20} color={colors.destructive} />
        <Text style={[styles.logoutText, { color: colors.destructive }]}>Sign Out</Text>
      </Pressable>

      <Text style={[styles.version, { color: colors.mutedForeground }]}>Grocerly v1.0.0</Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  authGate: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    padding: 40,
  },
  gateTitle: {
    fontSize: 22,
    fontFamily: 'Inter_700Bold',
    textAlign: 'center',
  },
  gateSubtitle: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
  },
  signInBtn: {
    marginTop: 8,
    paddingHorizontal: 40,
    paddingVertical: 14,
  },
  signInText: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
  },
  registerLink: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
  },
  profileHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    marginHorizontal: 16,
    padding: 16,
    borderWidth: 1,
    borderRadius: 16,
    marginBottom: 24,
  },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: 28,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: {
    fontSize: 20,
    fontFamily: 'Inter_700Bold',
    color: '#FFFFFF',
  },
  profileInfo: { flex: 1 },
  name: {
    fontSize: 17,
    fontFamily: 'Inter_700Bold',
  },
  email: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    marginTop: 2,
  },
  editBtn: {
    width: 32,
    height: 32,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sectionLabel: {
    fontSize: 12,
    fontFamily: 'Inter_600SemiBold',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
    marginHorizontal: 20,
    marginBottom: 8,
  },
  menuGroup: {
    marginHorizontal: 16,
    marginBottom: 20,
    borderWidth: 1,
    overflow: 'hidden',
  },
  menuItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 14,
  },
  menuIcon: {
    width: 36,
    height: 36,
    alignItems: 'center',
    justifyContent: 'center',
  },
  menuLabel: {
    flex: 1,
    fontSize: 15,
    fontFamily: 'Inter_500Medium',
  },
  menuRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  menuValue: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
  },
  separator: { height: 1, marginLeft: 62 },
  logoutBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    marginHorizontal: 16,
    padding: 14,
    borderWidth: 1,
    marginBottom: 20,
  },
  logoutText: {
    fontSize: 15,
    fontFamily: 'Inter_600SemiBold',
  },
  version: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
    marginBottom: 8,
  },
});
