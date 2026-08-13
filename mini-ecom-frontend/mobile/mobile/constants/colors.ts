/**
 * Grocerly design tokens — fresh green grocery palette
 */

const colors = {
  light: {
    // Legacy aliases
    text: '#111827',
    tint: '#22C55E',

    // Core surfaces
    background: '#FAFAF8',
    foreground: '#111827',

    // Cards / elevated surfaces
    card: '#FFFFFF',
    cardForeground: '#111827',

    // Primary action color — fresh green
    primary: '#22C55E',
    primaryForeground: '#FFFFFF',

    // Secondary
    secondary: '#DCFCE7',
    secondaryForeground: '#14532D',

    // Muted
    muted: '#F1F5F0',
    mutedForeground: '#6B7280',

    // Accent — orange for deals, flash sale
    accent: '#F97316',
    accentForeground: '#FFFFFF',

    // Destructive
    destructive: '#EF4444',
    destructiveForeground: '#FFFFFF',

    // Borders and inputs
    border: '#E5E7EB',
    input: '#F9FAFB',

    // Extra tokens
    success: '#16A34A',
    warning: '#FBBF24',
    info: '#3B82F6',
  },

  dark: {
    text: '#F0FDF4',
    tint: '#22C55E',

    background: '#0D1610',
    foreground: '#F0FDF4',

    card: '#111F14',
    cardForeground: '#F0FDF4',

    primary: '#22C55E',
    primaryForeground: '#052E16',

    secondary: '#1A3D22',
    secondaryForeground: '#86EFAC',

    muted: '#182B1B',
    mutedForeground: '#9CA3AF',

    accent: '#F97316',
    accentForeground: '#FFFFFF',

    destructive: '#EF4444',
    destructiveForeground: '#FFFFFF',

    border: '#1E3324',
    input: '#182B1B',

    success: '#22C55E',
    warning: '#FBBF24',
    info: '#60A5FA',
  },

  // Border radius in px — rounded feel for a friendly grocery app
  radius: 12,
};

export default colors;
