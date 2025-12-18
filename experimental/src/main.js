import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import { definePreset } from '@primevue/themes'
import ToastService from 'primevue/toastservice'
import 'primeicons/primeicons.css'
import './assets/css/main.css'

// Create a dark preset based on Aura with StraboSpot red (#dc3545)
const StraboAura = definePreset(Aura, {
  semantic: {
    primary: {
      50: '#fef2f2',
      100: '#fee2e2',
      200: '#fecaca',
      300: '#fca5a5',
      400: '#f87171',
      500: '#dc3545',
      600: '#dc3545',
      700: '#b91c1c',
      800: '#991b1b',
      900: '#7f1d1d',
      950: '#450a0a'
    },
    colorScheme: {
      dark: {
        primary: {
          color: '#dc3545',
          inverseColor: '#ffffff',
          hoverColor: '#c82333',
          activeColor: '#bd2130'
        }
      }
    }
  }
})

// Add dark-mode class to html element for PrimeVue
document.documentElement.classList.add('dark-mode')

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  theme: {
    preset: StraboAura,
    options: {
      darkModeSelector: '.dark-mode'
    }
  }
})
app.use(ToastService)

app.mount('#vue-app')
