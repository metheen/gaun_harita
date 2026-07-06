const colorVar = (name) => ({ opacityValue }) => {
  if (opacityValue === undefined) {
    return `rgb(var(${name}))`;
  }
  return `rgb(var(${name}) / ${opacityValue})`;
};

module.exports = {
  darkMode: 'class',
  content: ['./index.html'],
  theme: {
    extend: {
      colors: {
        'on-primary': colorVar('--color-on-primary'),
        'inverse-on-surface': colorVar('--color-inverse-on-surface'),
        'surface-container-highest': colorVar('--color-surface-container-highest'),
        'surface-container': colorVar('--color-surface-container'),
        'secondary-container': colorVar('--color-secondary-container'),
        'surface-container-low': colorVar('--color-surface-container-low'),
        'outline-variant': colorVar('--color-outline-variant'),
        'primary-container': colorVar('--color-primary-container'),
        'surface-container-lowest': colorVar('--color-surface-container-lowest'),
        outline: colorVar('--color-outline'),
        'surface-variant': colorVar('--color-surface-variant'),
        'on-primary-fixed-variant': colorVar('--color-on-primary-fixed-variant'),
        tertiary: colorVar('--color-tertiary'),
        'surface-dim': colorVar('--color-surface-dim'),
        'on-secondary-container': colorVar('--color-on-secondary-container'),
        'on-error': colorVar('--color-on-error'),
        background: colorVar('--color-background'),
        'on-surface-variant': colorVar('--color-on-surface-variant'),
        'on-background': colorVar('--color-on-background'),
        error: colorVar('--color-error'),
        'on-primary-container': colorVar('--color-on-primary-container'),
        'error-container': colorVar('--color-error-container'),
        secondary: colorVar('--color-secondary'),
        surface: colorVar('--color-surface'),
        primary: colorVar('--color-primary'),
        'surface-container-high': colorVar('--color-surface-container-high'),
        'on-surface': colorVar('--color-on-surface'),
        'cat-egitim': '#8ED1E1',
        'cat-arge': '#D48F1F',
        'cat-konut': '#CCB9D6',
        'cat-banka': '#2E8B22',
        'cat-kultur': '#D186B1',
        'cat-kutuphane': '#A86B3E',
        'cat-saglik': '#E55E5E',
        'cat-sosyal': '#1B1F96',
        'cat-spor': '#9E2992',
        'cat-yemek': '#E6D955',
        'cat-idari': '#666666'
      },
      borderRadius: {
        DEFAULT: '0.25rem',
        lg: '0.5rem',
        xl: '0.75rem',
        '2xl': '1rem',
        '3xl': '1.5rem',
        full: '9999px'
      },
      spacing: {
        unit: '4px',
        gutter: '16px',
        xl: '32px',
        xs: '4px',
        sm: '8px',
        lg: '24px',
        md: '16px'
      },
      fontFamily: {
        'body-lg': ['Inter'],
        'headline-lg': ['Inter'],
        'display-lg': ['Inter'],
        'title-md': ['Inter']
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/container-queries')
  ]
};
