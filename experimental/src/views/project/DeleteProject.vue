<template>
  <div>
    <div v-if="error" class="text-center py-12">
      <p class="text-red-400 mb-4">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary inline-block">Back to My Data</a>
    </div>

    <div v-else class="text-center py-12">
      <p class="text-strabo-text-secondary">Deleting project...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { projectService } from '@/services/api'

const props = defineProps({
  ppk: String
})

const error = ref(null)

// Confirmation happens on the referring page (my_experimental_data / my_data).
// This route deletes immediately and returns to the hub.
onMounted(async () => {
  if (!props.ppk) {
    error.value = 'Project ID is required'
    return
  }

  try {
    await projectService.delete(props.ppk)
    window.location.href = '/my_experimental_data'
  } catch (err) {
    console.error('Failed to delete project:', err)
    error.value = 'Failed to delete project. It may not exist or you may not have permission.'
  }
})
</script>
