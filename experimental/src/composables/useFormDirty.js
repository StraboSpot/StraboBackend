import { computed, ref } from 'vue'

/**
 * Tracks whether a form ref has been modified since its last snapshot.
 *
 * Usage: declare AFTER the form ref but BEFORE the immediate init watch,
 * then call resetDirty() at the end of the init routine so re-initialization
 * (e.g. bulk-load replacing initialData) starts from a clean baseline.
 */
export function useFormDirty(form) {
  const snapshot = ref(JSON.stringify(form.value))

  const resetDirty = () => {
    snapshot.value = JSON.stringify(form.value)
  }

  const isDirty = computed(() => JSON.stringify(form.value) !== snapshot.value)

  return { isDirty, resetDirty }
}
