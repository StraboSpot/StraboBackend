import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useAppStore = defineStore('app', () => {
  // User info from PHP session
  const userPkey = ref(window.STRABO_CONFIG?.userPkey || null)
  const username = ref(window.STRABO_CONFIG?.username || '')

  // UI state
  const loading = ref(false)
  const error = ref(null)

  // Computed
  const isLoggedIn = computed(() => !!userPkey.value)

  // Actions
  function setLoading(value) {
    loading.value = value
  }

  function setError(message) {
    error.value = message
  }

  function clearError() {
    error.value = null
  }

  return {
    userPkey,
    username,
    loading,
    error,
    isLoggedIn,
    setLoading,
    setError,
    clearError
  }
})
