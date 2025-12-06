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

const router = useRouter()
const saving = ref(false)

async function handleSubmit(formData) {
  saving.value = true
  try {
    // TODO: Call API to create facility
    // For now, just redirect back
    console.log('Creating facility:', formData)
    alert('Facility creation will be implemented with API integration')
    router.push('/apparatus_repository')
  } catch (error) {
    console.error('Failed to create facility:', error)
    alert('Failed to create facility. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
