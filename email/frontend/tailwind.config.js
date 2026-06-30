/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          50: 'rgb(var(--color-primary-50) / <alpha-value>)',
          100: 'rgb(var(--color-primary-100) / <alpha-value>)',
          200: 'rgb(var(--color-primary-200) / <alpha-value>)',
          300: 'rgb(var(--color-primary-300) / <alpha-value>)',
          400: 'rgb(var(--color-primary-400) / <alpha-value>)',
          500: 'rgb(var(--color-primary-500) / <alpha-value>)',
          600: 'rgb(var(--color-primary-600) / <alpha-value>)',
          700: 'rgb(var(--color-primary-700) / <alpha-value>)',
          800: 'rgb(var(--color-primary-800) / <alpha-value>)',
          900: 'rgb(var(--color-primary-900) / <alpha-value>)',
        },
        surface: {
          50: '#fafafa',
          100: '#f4f4f5',
          200: '#e4e4e7',
          300: '#d4d4d8',
          400: '#a1a1aa',
          500: '#71717a',
          600: '#52525b',
          700: '#3d3d47',
          750: '#32323c',
          800: '#26262e',
          850: '#1f1f26',
          900: '#1a1a20',
          950: '#121216',
        },
      },
      fontFamily: {
        sans: ['Outfit', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Consolas', 'monospace'],
      },
      borderRadius: {
        'pill': '9999px',
      },
      boxShadow: {
        'glow': '0 0 20px rgb(var(--color-glow) / 0.15)',
        'glow-lg': '0 0 40px rgb(var(--color-glow) / 0.2)',
      },
      gridTemplateColumns: {
        '14': 'repeat(14, minmax(0, 1fr))',
      },
    },
  },
  plugins: [
    // Restores readable typography defaults (paragraph spacing,
    // headings, lists, blockquotes, code) for any content rendered
    // inside a `prose` container. Used by the News reader's article
    // body so server-extracted HTML reads like a real magazine page
    // instead of a flat text dump.
    require('@tailwindcss/typography'),
  ],
}

