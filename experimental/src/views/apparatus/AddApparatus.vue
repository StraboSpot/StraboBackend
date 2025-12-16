<template>
  <div>
    <PageHeader
      title="New Apparatus"
      back-link="/apparatus_repository"
      subtitle="Add new equipment to the facility"
    />

    <ApparatusForm
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
import ApparatusForm from '@/components/forms/ApparatusForm.vue'
import { apparatusService } from '@/services/api'

const props = defineProps({
  f: String // Parent facility ID
})

const router = useRouter()
const saving = ref(false)

async function handleSubmit(formData) {
  saving.value = true
  try {
    const result = await apparatusService.create(props.f, formData)
    if (result.error) {
      alert('Error: ' + result.error)
    } else {
      router.push('/apparatus_repository')
    }
  } catch (error) {
    console.error('Failed to create apparatus:', error)
    alert('Failed to create apparatus. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
