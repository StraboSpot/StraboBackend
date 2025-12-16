<template>
  <div>
    <PageHeader
      title="New Facility"
      back-link="/apparatus_repository"
      subtitle="Add a new research facility to the repository"
    />

    <FacilityForm
      :initial-data="{}"
      :saving="saving"
      @submit="handleSubmit"
      @cancel="router.push('/apparatus_repository')"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import FacilityForm from '@/components/forms/FacilityForm.vue'
import { facilityService } from '@/services/api'

const router = useRouter()
const saving = ref(false)

async function handleSubmit(formData) {
  saving.value = true
  try {
    const result = await facilityService.create(formData)
    if (result.error) {
      alert('Error: ' + result.error)
    } else {
      router.push('/apparatus_repository')
    }
  } catch (error) {
    console.error('Failed to create facility:', error)
    alert('Failed to create facility. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
