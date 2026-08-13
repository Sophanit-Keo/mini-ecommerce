import React, { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,

  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Link, router } from 'expo-router';
import * as Haptics from 'expo-haptics';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { useColors } from '@/hooks/useColors';
import { useAuth } from '@/context/AuthContext';

export default function LoginScreen() {
  const colors = useColors();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPass, setShowPass] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleLogin = async () => {
    if (!email.trim() || !password) {
      setError('Please fill in all fields');
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      return;
    }
    setLoading(true);
    setError('');
    try {
      await login(email.trim(), password);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      router.dismissAll();
    } catch (err: any) {
      setError(err?.data?.error ?? 'Invalid email or password');
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAwareScrollView
      style={[styles.container, { backgroundColor: colors.background }]}
      contentContainerStyle={styles.content}
      bottomOffset={24}
      keyboardShouldPersistTaps="handled"
    >
      {/* Logo area */}
      <View style={[styles.logo, { backgroundColor: colors.secondary, borderRadius: 24 }]}>
        <Ionicons name="leaf" size={40} color={colors.primary} />
      </View>
      <Text style={[styles.title, { color: colors.foreground }]}>Welcome back</Text>
      <Text style={[styles.subtitle, { color: colors.mutedForeground }]}>
        Sign in to your Grocerly account
      </Text>

      {/* Demo hint */}
      <View style={[styles.demoHint, { backgroundColor: colors.secondary, borderRadius: colors.radius }]}>
        <Ionicons name="information-circle-outline" size={16} color={colors.primary} />
        <Text style={[styles.demoText, { color: colors.secondaryForeground }]}>
          Demo: demo@grocerly.com / demo123
        </Text>
      </View>

      {error ? (
        <View style={[styles.errorBox, { backgroundColor: colors.destructive + '15', borderRadius: colors.radius }]}>
          <Ionicons name="alert-circle-outline" size={16} color={colors.destructive} />
          <Text style={[styles.errorText, { color: colors.destructive }]}>{error}</Text>
        </View>
      ) : null}

      <Text style={[styles.label, { color: colors.foreground }]}>Email</Text>
      <TextInput
        style={[
          styles.input,
          {
            backgroundColor: colors.input,
            borderColor: colors.border,
            borderRadius: colors.radius,
            color: colors.foreground,
          },
        ]}
        placeholder="your@example.com"
        placeholderTextColor={colors.mutedForeground}
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        keyboardType="email-address"
        returnKeyType="next"
      />

      <Text style={[styles.label, { color: colors.foreground }]}>Password</Text>
      <View style={styles.passwordRow}>
        <TextInput
          style={[
            styles.input,
            styles.passwordInput,
            {
              backgroundColor: colors.input,
              borderColor: colors.border,
              borderRadius: colors.radius,
              color: colors.foreground,
            },
          ]}
          placeholder="••••••••"
          placeholderTextColor={colors.mutedForeground}
          value={password}
          onChangeText={setPassword}
          secureTextEntry={!showPass}
          returnKeyType="done"
          onSubmitEditing={handleLogin}
        />
        <Pressable
          style={[styles.eyeBtn, { right: 14 }]}
          onPress={() => setShowPass(!showPass)}
        >
          <Ionicons
            name={showPass ? 'eye-off-outline' : 'eye-outline'}
            size={20}
            color={colors.mutedForeground}
          />
        </Pressable>
      </View>

      <Pressable
        style={({ pressed }) => [
          styles.primaryBtn,
          {
            backgroundColor: colors.primary,
            borderRadius: colors.radius,
            opacity: pressed || loading ? 0.85 : 1,
          },
        ]}
        onPress={handleLogin}
        disabled={loading}
      >
        {loading ? (
          <ActivityIndicator color="#FFF" />
        ) : (
          <Text style={[styles.primaryBtnText, { color: colors.primaryForeground }]}>
            Sign In
          </Text>
        )}
      </Pressable>

      <View style={styles.footer}>
        <Text style={[styles.footerText, { color: colors.mutedForeground }]}>
          Don't have an account?
        </Text>
        <Link href="/(auth)/register" asChild>
          <Pressable>
            <Text style={[styles.linkText, { color: colors.primary }]}>Sign Up</Text>
          </Pressable>
        </Link>
      </View>
    </KeyboardAwareScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  content: {
    padding: 24,
    paddingTop: 40,
    gap: 12,
  },
  logo: {
    width: 80,
    height: 80,
    alignItems: 'center',
    justifyContent: 'center',
    alignSelf: 'center',
    marginBottom: 8,
  },
  title: {
    fontSize: 28,
    fontFamily: 'Inter_700Bold',
    textAlign: 'center',
  },
  subtitle: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    textAlign: 'center',
    marginBottom: 8,
  },
  demoHint: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 12,
    marginBottom: 4,
  },
  demoText: {
    fontSize: 12,
    fontFamily: 'Inter_400Regular',
    flex: 1,
  },
  errorBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 12,
  },
  errorText: {
    fontSize: 13,
    fontFamily: 'Inter_400Regular',
    flex: 1,
  },
  label: {
    fontSize: 14,
    fontFamily: 'Inter_500Medium',
    marginBottom: -4,
  },
  input: {
    fontSize: 15,
    fontFamily: 'Inter_400Regular',
    padding: 14,
    borderWidth: 1,
  },
  passwordRow: {
    position: 'relative',
  },
  passwordInput: {
    paddingRight: 48,
  },
  eyeBtn: {
    position: 'absolute',
    top: 0,
    bottom: 0,
    justifyContent: 'center',
  },
  primaryBtn: {
    paddingVertical: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 8,
  },
  primaryBtnText: {
    fontSize: 16,
    fontFamily: 'Inter_600SemiBold',
  },
  footer: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 4,
    marginTop: 8,
  },
  footerText: {
    fontSize: 14,
    fontFamily: 'Inter_400Regular',
  },
  linkText: {
    fontSize: 14,
    fontFamily: 'Inter_600SemiBold',
  },
});
