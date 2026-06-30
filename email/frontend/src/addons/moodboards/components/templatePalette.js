/**
 * Shared design color palette for all premade templates.
 * Single source of truth — change a value here, all templates reflect it.
 * These are auto-registered as global colors when a template is placed.
 */

export const C = {
  // Dark backgrounds
  bgDark: '#0f172a',
  bgCard: '#1e293b',
  bgInput: '#0f172a',
  bgOverlay: '#000000',

  // Dark borders
  borderSubtle: '#334155',
  borderMuted: '#475569',

  // Brand / Primary
  primary: '#6366f1',
  primarySoft: '#312e81',
  primaryLight: '#a5b4fc',
  primaryDeep: '#1e1b4b',
  primaryHover: '#4338ca',
  purple: '#8b5cf6',
  purpleSoft: '#2e1065',
  purpleLight: '#c4b5fd',

  // Semantic — success
  success: '#22c55e',
  successSoft: '#14532d',
  successLight: '#4ade80',
  successBg: '#dcfce7',
  successText: '#166534',

  // Semantic — danger
  danger: '#ef4444',
  dangerSoft: '#7f1d1d',
  dangerLight: '#fca5a5',
  dangerBg: '#fef2f2',
  dangerText: '#991b1b',
  dangerBold: '#dc2626',

  // Semantic — warning
  warning: '#eab308',
  warningSoft: '#78350f',
  warningLight: '#fcd34d',
  warningBg: '#fffbeb',
  warningText: '#92400e',
  warningBold: '#d97706',

  // Semantic — info
  infoSoft: '#1e3a5f',
  infoLight: '#7dd3fc',

  // Typography dark
  textPrimary: '#f1f5f9',
  textSecondary: '#94a3b8',
  textMuted: '#64748b',
  textFaint: '#475569',
  textBody: '#cbd5e1',

  // Typography light
  textLightPrimary: '#0f172a',
  textLightSecondary: '#64748b',
  textLightMuted: '#94a3b8',
  textLightBody: '#6b7280',

  // Light backgrounds
  bgLight: '#ffffff',
  bgCardLight: '#f8fafc',
  bgCardHover: '#f1f5f9',
  borderLight: '#e2e8f0',
  borderLightSubtle: '#d1d5db',

  // Misc
  white: '#ffffff',
  accent: '#818cf8',
  greenBg: '#1a2e05',
  greenLight: '#86efac',
  ctaLight: '#c7d2fe',
  greenBold: '#16a34a',
}

function camelToKebab(s) {
  return s.replace(/([A-Z])/g, '-$1').toLowerCase()
}

export const templateGlobalColors = Object.entries(C).map(([key, hex]) => ({
  id: `tpl-${camelToKebab(key)}`,
  name: camelToKebab(key),
  value: hex,
}))
