<template>
  <div>
    <PageHeader
      :title="facility?.name || 'Facility'"
      show-back
      @back="goBack"
    />

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading facility...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="alert-error">
      {{ error }}
    </div>

    <!-- Content -->
    <div v-else-if="facility">
      <!-- Basic Info -->
      <div class="card mb-6">
        <h2 class="section-header">Facility Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <InfoField label="Name" :value="facility.name" />
          <InfoField label="Type" :value="facility.type" />
          <InfoField label="Institute" :value="facility.institute" />
          <InfoField label="Department" :value="facility.department" />
          <InfoField label="Website" :value="facility.website" is-link />
          <InfoField label="Description" :value="facility.description" class="md:col-span-2" />
        </div>
      </div>

      <!-- Address -->
      <div v-if="facility.address" class="card mb-6">
        <h2 class="section-header">Address</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <InfoField label="Building" :value="facility.address.building" />
          <InfoField label="Street" :value="facility.address.street" />
          <InfoField label="City" :value="facility.address.city" />
          <InfoField label="State/Province" :value="facility.address.state" />
          <InfoField label="Country" :value="facility.address.country" />
          <InfoField label="Postal Code" :value="facility.address.postcode" />
          <InfoField label="Latitude" :value="facility.address.latitude" />
          <InfoField label="Longitude" :value="facility.address.longitude" />
        </div>
      </div>

      <!-- Contact -->
      <div v-if="facility.contact" class="card mb-6">
        <h2 class="section-header">Contact</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <InfoField label="Name" :value="`${facility.contact.firstname || ''} ${facility.contact.lastname || ''}`.trim()" />
          <InfoField label="Affiliation" :value="facility.contact.affiliation" />
          <InfoField label="Email" :value="facility.contact.email" is-email />
          <InfoField label="Phone" :value="facility.contact.phone" />
          <InfoField label="Website" :value="facility.contact.website" is-link />
          <InfoField label="ORCID" :value="facility.contact.id" />
        </div>
      </div>

      <!-- Timestamps -->
      <div class="text-sm text-strabo-text-secondary">
        <p v-if="facility.created_timestamp">Created: {{ facility.created_timestamp }}</p>
        <p v-if="facility.modified_timestamp">Modified: {{ facility.modified_timestamp }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import InfoField from '@/components/InfoField.vue'
import { facilityService } from '@/services/api'

const props = defineProps({
  f: String
})

const router = useRouter()
const facility = ref(null)
const loading = ref(true)
const error = ref(null)

function goBack() {
  router.back()
}

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
</script>
