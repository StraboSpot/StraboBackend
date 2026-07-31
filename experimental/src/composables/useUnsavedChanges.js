import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Page-level unsaved-changes tracking for the Add/Edit Experiment views.
 *
 * serialize: () => string — stable serialization of the page's saveable state.
 * The page stays "clean" until markSaved() establishes the baseline (call it
 * once initial data has loaded, and again after a successful save).
 * suppressGuards() disarms the beforeunload prompt for intentional
 * post-save/discard redirects.
 */
export function useUnsavedChanges(serialize) {
  const savedSnapshot = ref(null)
  const suppressed = ref(false)

  const isDirty = computed(() =>
    !suppressed.value &&
    savedSnapshot.value !== null &&
    serialize() !== savedSnapshot.value
  )

  const markSaved = () => {
    savedSnapshot.value = serialize()
  }

  const suppressGuards = () => {
    suppressed.value = true
  }

  const onBeforeUnload = (e) => {
    if (isDirty.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))

  return { isDirty, markSaved, suppressGuards }
}
