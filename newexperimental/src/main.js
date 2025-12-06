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

// Create a dark preset based on Aura
const StraboAura = definePreset(Aura, {
  semantic: {
    primary: {
      50: '{orange.50}',
      100: '{orange.100}',
      200: '{orange.200}',
      300: '{orange.300}',
      400: '{orange.400}',
      500: '{orange.500}',
      600: '{orange.600}',
      700: '{orange.700}',
      800: '{orange.800}',
      900: '{orange.900}',
      950: '{orange.950}'
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
