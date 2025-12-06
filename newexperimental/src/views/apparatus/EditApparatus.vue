<template>
  <div>
    <PageHeader
      :title="`Edit: ${apparatus?.name || 'Apparatus'}`"
      :back-link="`/view_apparatus?a=${a}`"
    />

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading apparatus...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="alert-error">
      {{ error }}
    </div>

    <!-- Form -->
    <ApparatusForm
      v-else-if="apparatus"
      :initial-data="apparatus"
      :saving="saving"
      submit-label="Save Changes"
      @submit="handleSubmit"
      @cancel="router.push(`/view_apparatus?a=${a}`)"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ApparatusForm from '@/components/forms/ApparatusForm.vue'
import { apparatusService } from '@/services/api'

const props = defineProps({
  a: String
})

const router = useRouter()
const apparatus = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref(null)

onMounted(async () => {
  try {
    apparatus.value = await apparatusService.get(props.a)
    if (apparatus.value.Error) {
      error.value = apparatus.value.Error
      apparatus.value = null
    }
  } catch (err) {
    console.error('Failed to load apparatus:', err)
    error.value = 'Failed to load apparatus.'
  } finally {
    loading.value = false
  }
})

async function handleSubmit(formData) {
  saving.value = true
  try {
    // TODO: Call API to update apparatus
    console.log('Updating apparatus:', formData)
    alert('Apparatus update will be implemented with API integration')
    router.push(`/view_apparatus?a=${props.a}`)
  } catch (err) {
    console.error('Failed to update apparatus:', err)
    alert('Failed to save changes. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
