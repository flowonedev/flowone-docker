/**
 * Presentation Themes
 * 
 * Professional presentation themes with color palettes, font pairings, and styling.
 * Each theme includes colors, typography, and default slide styling.
 */

/**
 * Theme definitions
 */
export const presentationThemes = {
  // ============================================================
  // MODERN - Clean, minimal design
  // ============================================================
  modern: {
    id: 'modern',
    name: 'Modern',
    description: 'Clean, minimal design with sharp contrasts',
    preview: {
      background: '#ffffff',
      accent: '#2563eb',
      text: '#1f2937',
    },
    colors: {
      primary: '#2563eb',
      primaryDark: '#1d4ed8',
      secondary: '#64748b',
      accent: '#0891b2',
      background: '#ffffff',
      surface: '#f8fafc',
      text: '#1f2937',
      textLight: '#64748b',
      textOnPrimary: '#ffffff',
      success: '#10b981',
      warning: '#f59e0b',
      error: '#ef4444',
    },
    fonts: {
      heading: 'Inter',
      body: 'Inter',
      code: 'JetBrains Mono',
    },
    slideBackground: {
      type: 'solid',
      value: '#ffffff',
    },
    styles: {
      titleSize: 48,
      bodySize: 24,
      headingWeight: 'bold',
      bodyWeight: 'normal',
      borderRadius: 8,
    },
  },

  // ============================================================
  // CORPORATE - Professional business style
  // ============================================================
  corporate: {
    id: 'corporate',
    name: 'Corporate',
    description: 'Professional blue tones for business presentations',
    preview: {
      background: '#f0f4f8',
      accent: '#1e3a5f',
      text: '#1e3a5f',
    },
    colors: {
      primary: '#1e3a5f',
      primaryDark: '#162d4d',
      secondary: '#3b82f6',
      accent: '#0ea5e9',
      background: '#ffffff',
      surface: '#f0f4f8',
      text: '#1e3a5f',
      textLight: '#475569',
      textOnPrimary: '#ffffff',
      success: '#059669',
      warning: '#d97706',
      error: '#dc2626',
    },
    fonts: {
      heading: 'Poppins',
      body: 'Open Sans',
      code: 'Source Code Pro',
    },
    slideBackground: {
      type: 'solid',
      value: '#ffffff',
    },
    styles: {
      titleSize: 44,
      bodySize: 22,
      headingWeight: '600',
      bodyWeight: 'normal',
      borderRadius: 4,
    },
  },

  // ============================================================
  // CREATIVE - Bold, vibrant design
  // ============================================================
  creative: {
    id: 'creative',
    name: 'Creative',
    description: 'Bold colors and dynamic layouts',
    preview: {
      background: '#fef3c7',
      accent: '#f97316',
      text: '#1f2937',
    },
    colors: {
      primary: '#f97316',
      primaryDark: '#ea580c',
      secondary: '#8b5cf6',
      accent: '#ec4899',
      background: '#fffbeb',
      surface: '#fef3c7',
      text: '#1f2937',
      textLight: '#78716c',
      textOnPrimary: '#ffffff',
      success: '#22c55e',
      warning: '#eab308',
      error: '#f43f5e',
    },
    fonts: {
      heading: 'Montserrat',
      body: 'Lato',
      code: 'Fira Code',
    },
    slideBackground: {
      type: 'solid',
      value: '#fffbeb',
    },
    styles: {
      titleSize: 52,
      bodySize: 24,
      headingWeight: 'bold',
      bodyWeight: 'normal',
      borderRadius: 16,
    },
  },

  // ============================================================
  // DARK MODE - Dark backgrounds with accent colors
  // ============================================================
  dark: {
    id: 'dark',
    name: 'Dark Mode',
    description: 'Dark backgrounds for low-light environments',
    preview: {
      background: '#0f172a',
      accent: '#38bdf8',
      text: '#f1f5f9',
    },
    colors: {
      primary: '#38bdf8',
      primaryDark: '#0ea5e9',
      secondary: '#a78bfa',
      accent: '#22d3ee',
      background: '#0f172a',
      surface: '#1e293b',
      text: '#f1f5f9',
      textLight: '#94a3b8',
      textOnPrimary: '#0f172a',
      success: '#34d399',
      warning: '#fbbf24',
      error: '#f87171',
    },
    fonts: {
      heading: 'Inter',
      body: 'Inter',
      code: 'JetBrains Mono',
    },
    slideBackground: {
      type: 'solid',
      value: '#0f172a',
    },
    styles: {
      titleSize: 48,
      bodySize: 24,
      headingWeight: 'bold',
      bodyWeight: 'normal',
      borderRadius: 8,
    },
  },

  // ============================================================
  // GRADIENT - Vibrant gradient backgrounds
  // ============================================================
  gradient: {
    id: 'gradient',
    name: 'Gradient',
    description: 'Vibrant gradient backgrounds',
    preview: {
      background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
      accent: '#ffffff',
      text: '#ffffff',
    },
    colors: {
      primary: '#667eea',
      primaryDark: '#5a67d8',
      secondary: '#764ba2',
      accent: '#f472b6',
      background: '#667eea',
      surface: 'rgba(255, 255, 255, 0.1)',
      text: '#ffffff',
      textLight: 'rgba(255, 255, 255, 0.8)',
      textOnPrimary: '#ffffff',
      success: '#4ade80',
      warning: '#fcd34d',
      error: '#fb7185',
    },
    fonts: {
      heading: 'Poppins',
      body: 'Inter',
      code: 'Fira Code',
    },
    slideBackground: {
      type: 'gradient',
      value: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
    },
    styles: {
      titleSize: 52,
      bodySize: 24,
      headingWeight: 'bold',
      bodyWeight: 'normal',
      borderRadius: 12,
    },
  },

  // ============================================================
  // MINIMAL - Ultra-clean with lots of whitespace
  // ============================================================
  minimal: {
    id: 'minimal',
    name: 'Minimal',
    description: 'Ultra-clean with focus on content',
    preview: {
      background: '#fafafa',
      accent: '#18181b',
      text: '#18181b',
    },
    colors: {
      primary: '#18181b',
      primaryDark: '#09090b',
      secondary: '#71717a',
      accent: '#a1a1aa',
      background: '#fafafa',
      surface: '#ffffff',
      text: '#18181b',
      textLight: '#71717a',
      textOnPrimary: '#ffffff',
      success: '#22c55e',
      warning: '#f59e0b',
      error: '#ef4444',
    },
    fonts: {
      heading: 'DM Sans',
      body: 'DM Sans',
      code: 'IBM Plex Mono',
    },
    slideBackground: {
      type: 'solid',
      value: '#fafafa',
    },
    styles: {
      titleSize: 42,
      bodySize: 22,
      headingWeight: '500',
      bodyWeight: 'normal',
      borderRadius: 0,
    },
  },

  // ============================================================
  // NATURE - Earthy, organic colors
  // ============================================================
  nature: {
    id: 'nature',
    name: 'Nature',
    description: 'Earthy tones inspired by nature',
    preview: {
      background: '#f5f5f0',
      accent: '#16a34a',
      text: '#365314',
    },
    colors: {
      primary: '#16a34a',
      primaryDark: '#15803d',
      secondary: '#84cc16',
      accent: '#65a30d',
      background: '#f5f5f0',
      surface: '#ecfccb',
      text: '#365314',
      textLight: '#4d7c0f',
      textOnPrimary: '#ffffff',
      success: '#22c55e',
      warning: '#ca8a04',
      error: '#dc2626',
    },
    fonts: {
      heading: 'Playfair Display',
      body: 'Source Sans Pro',
      code: 'Source Code Pro',
    },
    slideBackground: {
      type: 'solid',
      value: '#f5f5f0',
    },
    styles: {
      titleSize: 46,
      bodySize: 24,
      headingWeight: '600',
      bodyWeight: 'normal',
      borderRadius: 8,
    },
  },

  // ============================================================
  // SUNSET - Warm, inviting colors
  // ============================================================
  sunset: {
    id: 'sunset',
    name: 'Sunset',
    description: 'Warm orange and red tones',
    preview: {
      background: '#fff7ed',
      accent: '#ea580c',
      text: '#7c2d12',
    },
    colors: {
      primary: '#ea580c',
      primaryDark: '#c2410c',
      secondary: '#f97316',
      accent: '#fb923c',
      background: '#fff7ed',
      surface: '#ffedd5',
      text: '#7c2d12',
      textLight: '#9a3412',
      textOnPrimary: '#ffffff',
      success: '#65a30d',
      warning: '#d97706',
      error: '#b91c1c',
    },
    fonts: {
      heading: 'Raleway',
      body: 'Nunito',
      code: 'Fira Code',
    },
    slideBackground: {
      type: 'gradient',
      value: 'linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%)',
    },
    styles: {
      titleSize: 48,
      bodySize: 24,
      headingWeight: 'bold',
      bodyWeight: 'normal',
      borderRadius: 12,
    },
  },

  // ============================================================
  // OCEAN - Cool blue tones
  // ============================================================
  ocean: {
    id: 'ocean',
    name: 'Ocean',
    description: 'Cool, calming blue palette',
    preview: {
      background: '#ecfeff',
      accent: '#0891b2',
      text: '#164e63',
    },
    colors: {
      primary: '#0891b2',
      primaryDark: '#0e7490',
      secondary: '#06b6d4',
      accent: '#22d3ee',
      background: '#ecfeff',
      surface: '#cffafe',
      text: '#164e63',
      textLight: '#155e75',
      textOnPrimary: '#ffffff',
      success: '#14b8a6',
      warning: '#eab308',
      error: '#f43f5e',
    },
    fonts: {
      heading: 'Quicksand',
      body: 'Work Sans',
      code: 'JetBrains Mono',
    },
    slideBackground: {
      type: 'gradient',
      value: 'linear-gradient(180deg, #ecfeff 0%, #cffafe 100%)',
    },
    styles: {
      titleSize: 46,
      bodySize: 24,
      headingWeight: '600',
      bodyWeight: 'normal',
      borderRadius: 16,
    },
  },
}

/**
 * Get theme list for UI display
 */
export function getThemeList() {
  return Object.values(presentationThemes).map(t => ({
    id: t.id,
    name: t.name,
    description: t.description,
    preview: t.preview,
  }))
}

/**
 * Get theme by ID
 */
export function getTheme(themeId) {
  return presentationThemes[themeId] || presentationThemes.modern
}

/**
 * Apply theme to a slide background
 */
export function getThemeBackground(themeId) {
  const theme = getTheme(themeId)
  return theme.slideBackground
}

/**
 * Get theme colors
 */
export function getThemeColors(themeId) {
  const theme = getTheme(themeId)
  return theme.colors
}

/**
 * Get theme fonts
 */
export function getThemeFonts(themeId) {
  const theme = getTheme(themeId)
  return theme.fonts
}

/**
 * Get theme styles
 */
export function getThemeStyles(themeId) {
  const theme = getTheme(themeId)
  return theme.styles
}

/**
 * Apply theme to an existing text object
 */
export function applyThemeToText(textObject, themeId, isTitle = false) {
  const theme = getTheme(themeId)
  return {
    ...textObject,
    fontFamily: isTitle ? theme.fonts.heading : theme.fonts.body,
    fontSize: isTitle ? theme.styles.titleSize : theme.styles.bodySize,
    fontWeight: isTitle ? theme.styles.headingWeight : theme.styles.bodyWeight,
    color: theme.colors.text,
  }
}

/**
 * Apply theme to a shape object
 */
export function applyThemeToShape(shapeObject, themeId) {
  const theme = getTheme(themeId)
  return {
    ...shapeObject,
    fill: theme.colors.primary,
    stroke: theme.colors.primaryDark,
  }
}

export default presentationThemes

