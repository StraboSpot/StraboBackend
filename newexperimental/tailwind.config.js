/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./src/**/*.{vue,js,ts,jsx,tsx}",
    "./index.html"
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // StraboSpot dark theme colors
        'strabo': {
          'bg-primary': '#1a1a1a',
          'bg-secondary': '#2d2d2d',
          'bg-tertiary': '#3a3a3a',
          'text-primary': '#e0e0e0',
          'text-secondary': '#9e9e9e',
          'accent': '#f4511e',
          'accent-hover': '#ff7043',
          'success': '#4caf50',
          'warning': '#ffb74d',
          'error': '#ef5350',
          'border': '#424242'
        }
      },
      fontFamily: {
        'sans': ['Roboto', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif']
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms')
  ]
}
