// PrimeVue configuration
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import 'primeicons/primeicons.css'

// Custom theme preset extending Aura dark
const StraboPreset = {
  preset: Aura,
  options: {
    darkModeSelector: '.dark-mode',
    cssLayer: false
  }
}

export { PrimeVue, StraboPreset }
