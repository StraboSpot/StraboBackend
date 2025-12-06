<template>
  <div>
    <PageHeader
      title="New Apparatus"
      :back-link="f ? `/view_facility?f=${f}` : '/apparatus_repository'"
      subtitle="Add new equipment to the facility"
    />

    <ApparatusForm
      :initial-data="{}"
      :saving="saving"
      @submit="handleSubmit"
      @cancel="goBack"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ApparatusForm from '@/components/forms/ApparatusForm.vue'

const props = defineProps({
  f: String // Parent facility ID
})

const router = useRouter()
const saving = ref(false)

function goBack() {
  if (props.f) {
    router.push(`/view_facility?f=${props.f}`)
  } else {
    router.push('/apparatus_repository')
  }
}

async function handleSubmit(formData) {
  saving.value = true
  try {
    // TODO: Call API to create apparatus
    console.log('Creating apparatus for facility:', props.f, formData)
    alert('Apparatus creation will be implemented with API integration')
    goBack()
  } catch (error) {
    console.error('Failed to create apparatus:', error)
    alert('Failed to create apparatus. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
