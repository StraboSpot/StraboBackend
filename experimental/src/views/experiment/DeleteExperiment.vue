<template>
  <div>
    <div v-if="error" class="text-center py-12">
      <p class="text-red-500 mb-4">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary">Back to My Data</a>
    </div>

    <div v-else class="text-center py-12">
      <p class="text-strabo-text-secondary">Deleting experiment...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { experimentService } from '@/services/api'

const props = defineProps({
  e: String
})

const error = ref(null)

// Confirmation happens on the referring page (my_experimental_data / my_data).
// This route deletes immediately and returns to the hub.
onMounted(async () => {
  if (!props.e) {
    error.value = 'Experiment ID is required'
    return
  }

  try {
    await experimentService.delete(props.e)
    window.location.href = '/my_experimental_data'
  } catch (err) {
    console.error('Failed to delete experiment:', err)
    error.value = 'Failed to delete experiment. It may not exist or you may not have permission.'
  }
})
</script>
