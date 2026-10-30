import type { Config } from 'tailwindcss';

// Flat brand: no rounded corners anywhere (PRODUCT decision), colors come from CSS tokens.
const token = (name: string) => `hsl(var(--${name}) / <alpha-value>)`;

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: 'class',
  theme: {
    borderRadius: { none: '0', DEFAULT: '0', sm: '0', md: '0', lg: '0', xl: '0', full: '0' },
    extend: {
      colors: {
        background: token('background'),
        foreground: token('foreground'),
        card: token('card'),
        muted: token('muted'),
        'muted-foreground': token('muted-foreground'),
        border: token('border'),
        input: token('input'),
        ring: token('ring'),
        primary: token('primary'),
        'primary-foreground': token('primary-foreground'),
        link: token('link'),
        destructive: token('destructive'),
        success: token('success'),
        warning: token('warning'),
        'brand-field': token('brand-field'),
      },
      fontFamily: {
        sans: ['var(--font-sans)'],
        latin: ['"Inter"', 'system-ui', 'sans-serif'],
      },
      keyframes: {
        'rise-in': { from: { opacity: '0', transform: 'translateY(6px)' }, to: { opacity: '1', transform: 'none' } },
      },
      animation: {
        'rise-in': 'rise-in 220ms cubic-bezier(0.23, 1, 0.32, 1) both',
      },
    },
  },
  plugins: [],
} satisfies Config;
