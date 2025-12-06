<template>
  <div>
    <PageHeader
      :title="apparatus?.name || 'Apparatus'"
      @back="goBack"
    />

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading apparatus...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="alert-error">
      {{ error }}
    </div>

    <!-- Content -->
    <div v-else-if="apparatus" class="max-w-4xl">
      <!-- Actions -->
      <div class="flex gap-2 mb-6">
        <router-link v-if="apparatus.can_edit" :to="`/edit_apparatus?a=${a}`" class="btn-primary">
          Edit Apparatus
        </router-link>
        <button @click="goBack" class="btn-secondary">
          Back to Repository
        </button>
      </div>

      <!-- Basic Info -->
      <div class="card mb-6">
        <h2 class="section-header">Apparatus Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <InfoField label="Name" :value="apparatus.name" />
          <InfoField label="Type" :value="apparatus.type" />
          <InfoField label="Location" :value="apparatus.location" />
          <InfoField label="ID" :value="apparatus.id" />
          <InfoField label="Description" :value="apparatus.description" class="md:col-span-2" />
        </div>
      </div>

      <!-- Features -->
      <div v-if="apparatus.features?.length > 0" class="card mb-6">
        <h2 class="section-header">Features</h2>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="feature in apparatus.features"
            :key="feature"
            class="px-3 py-1 bg-strabo-bg-tertiary rounded-full text-sm text-strabo-text-primary"
          >
            {{ feature }}
          </span>
        </div>
      </div>

      <!-- Parameters -->
      <div v-if="apparatus.parameters?.length > 0" class="card mb-6">
        <h2 class="section-header">Parameters</h2>
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Min</th>
              <th>Max</th>
              <th>Unit</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(param, idx) in apparatus.parameters" :key="idx">
              <td>{{ param.type }}</td>
              <td>{{ param.min || '—' }}</td>
              <td>{{ param.max || '—' }}</td>
              <td>{{ formatUnit(param) }}</td>
              <td>{{ param.note || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Documents -->
      <div v-if="apparatus.documents?.length > 0" class="card mb-6">
        <h2 class="section-header">Documents</h2>
        <div class="space-y-2">
          <div
            v-for="doc in apparatus.documents"
            :key="doc.uuid"
            class="flex items-center justify-between p-3 bg-strabo-bg-tertiary rounded"
          >
            <div>
              <span class="text-strabo-text-primary">{{ doc.path || doc.id || 'Document' }}</span>
              <span class="text-sm text-strabo-text-secondary ml-2">({{ doc.type }})</span>
            </div>
            <a
              v-if="doc.path"
              :href="doc.path"
              target="_blank"
              class="text-strabo-accent hover:underline text-sm"
            >
              View
            </a>
          </div>
        </div>
      </div>

      <!-- Timestamps -->
      <div class="text-sm text-strabo-text-secondary">
        <p v-if="apparatus.created_timestamp">Created: {{ apparatus.created_timestamp }}</p>
        <p v-if="apparatus.modified_timestamp">Modified: {{ apparatus.modified_timestamp }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import InfoField from '@/components/InfoField.vue'
import { apparatusService } from '@/services/api'

const props = defineProps({
  a: String
})

const router = useRouter()
const apparatus = ref(null)
const loading = ref(true)
const error = ref(null)

function goBack() {
  router.back()
}

function formatUnit(param) {
  if (!param.unit) return '—'
  if (param.prefix && param.prefix !== '(none)') {
    return `${param.prefix} ${param.unit}`
  }
  return param.unit
}

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
</script>
