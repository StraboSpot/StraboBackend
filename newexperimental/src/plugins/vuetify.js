// Vuetify configuration
import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

// Custom dark theme matching StraboSpot colors
const straboTheme = {
  dark: true,
  colors: {
    background: '#1a1a1a',
    surface: '#2d2d2d',
    'surface-variant': '#3a3a3a',
    'on-surface-variant': '#e0e0e0',
    primary: '#f4511e',
    'primary-darken-1': '#d84315',
    secondary: '#3a3a3a',
    'secondary-darken-1': '#2d2d2d',
    error: '#ef5350',
    info: '#2196F3',
    success: '#4caf50',
    warning: '#ffb74d',
    'on-background': '#e0e0e0',
    'on-surface': '#e0e0e0',
    'on-primary': '#ffffff',
    'on-secondary': '#e0e0e0',
    'on-error': '#ffffff',
    'on-info': '#ffffff',
    'on-success': '#ffffff',
    'on-warning': '#000000',
  },
  variables: {
    'border-color': '#424242',
    'border-opacity': 1,
    'high-emphasis-opacity': 0.87,
    'medium-emphasis-opacity': 0.60,
    'disabled-opacity': 0.38,
    'idle-opacity': 0.04,
    'hover-opacity': 0.08,
    'focus-opacity': 0.12,
    'selected-opacity': 0.08,
    'activated-opacity': 0.12,
    'pressed-opacity': 0.12,
    'dragged-opacity': 0.08,
  }
}

export default createVuetify({
  components,
  directives,
  theme: {
    defaultTheme: 'straboTheme',
    themes: {
      straboTheme
    }
  },
  defaults: {
    VTextField: {
      variant: 'outlined',
      density: 'comfortable',
      color: 'primary',
    },
    VSelect: {
      variant: 'outlined',
      density: 'comfortable',
      color: 'primary',
    },
    VTextarea: {
      variant: 'outlined',
      density: 'comfortable',
      color: 'primary',
    },
    VBtn: {
      variant: 'flat',
    },
    VCard: {
      elevation: 0,
    },
    VExpansionPanels: {
      variant: 'accordion',
    }
  }
})
