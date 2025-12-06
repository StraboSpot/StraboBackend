<template>
  <div>
    <PageHeader
      :title="`Edit: ${facility?.name || 'Facility'}`"
      :back-link="`/view_facility?f=${f}`"
    />

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading facility...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="alert-error">
      {{ error }}
    </div>

    <!-- Form -->
    <FacilityForm
      v-else-if="facility"
      :initial-data="facility"
      :saving="saving"
      submit-label="Save Changes"
      @submit="handleSubmit"
      @cancel="router.push(`/view_facility?f=${f}`)"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import FacilityForm from '@/components/forms/FacilityForm.vue'
import { facilityService } from '@/services/api'

const props = defineProps({
  f: String
})

const router = useRouter()
const facility = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref(null)

onMounted(async () => {
  try {
    facility.value = await facilityService.get(props.f)
    if (facility.value.Error) {
      error.value = facility.value.Error
      facility.value = null
    }
  } catch (err) {
    console.error('Failed to load facility:', err)
    error.value = 'Failed to load facility.'
  } finally {
    loading.value = false
  }
})

async function handleSubmit(formData) {
  saving.value = true
  try {
    // TODO: Call API to update facility
    console.log('Updating facility:', formData)
    alert('Facility update will be implemented with API integration')
    router.push(`/view_facility?f=${props.f}`)
  } catch (err) {
    console.error('Failed to update facility:', err)
    alert('Failed to save changes. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
