<template>
  <div>
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading...</p>
    </div>

    <div v-else-if="error" class="text-center py-12">
      <p class="text-red-500 mb-4">{{ error }}</p>
      <router-link :to="`/view_project?ppk=${ppk}`" class="btn-secondary">
        Back to Project
      </router-link>
    </div>

    <template v-else>
      <!-- Download button (top right, only visible when there's data) -->
      <div class="flex justify-end px-4 mb-4">
        <button
          v-if="hasData"
          @click="handleDownload"
          class="download-btn"
          title="Download Experiment"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
        </button>
      </div>

      <!-- Centered Header -->
      <div class="text-center mb-6">
        <h1 class="page-title">Add Experiment</h1>
        <p v-if="projectName" class="page-subtitle">Project: {{ projectName }}</p>
      </div>

      <!-- Experiment ID field -->
      <div class="max-w-3xl mx-auto px-4 mb-6">
        <div class="form-section">
          <label for="experimentId" class="form-label">Experiment ID</label>
          <InputText
            id="experimentId"
            v-model="experimentId"
            placeholder="Enter experiment identifier..."
            class="w-full"
          />
        </div>
      </div>

      <!-- Section Tiles -->
      <ExperimentTiles
        :experiment-data="experimentData"
        @open-section="handleOpenSection"
      />

      <!-- Action Buttons -->
      <div class="flex justify-center gap-4 mt-8 mb-8">
        <router-link :to="`/view_project?ppk=${ppk}`" class="btn-secondary">
          Cancel
        </router-link>
        <button @click="handleSave" :disabled="saving" class="btn-primary">
          {{ saving ? 'Saving...' : 'Save Experiment' }}
        </button>
      </div>

      <!-- Section Modals -->
      <SectionModal
        v-if="activeSection"
        :section="activeSection"
        :data="getSectionData(activeSection)"
        :readonly="false"
        :selected-features="getSelectedFeatures()"
        @close="activeSection = null"
        @save="handleSectionSave"
      />

      <!-- Download Modal -->
      <DownloadModal
        :visible="showDownloadModal"
        :data="downloadData"
        :filename="experimentId"
        @close="showDownloadModal = false"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import ExperimentTiles from '@/components/ExperimentTiles.vue'
import SectionModal from '@/components/SectionModal.vue'
import DownloadModal from '@/components/DownloadModal.vue'
import { projectService, experimentService } from '@/services/api'

const props = defineProps({
  ppk: String
})

const router = useRouter()

const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const projectName = ref('')
const experimentId = ref('')
const activeSection = ref(null)
const showDownloadModal = ref(false)

// LAPS data structure
const experimentData = ref({
  facility: {},
  apparatus: {},
  daq: {},
  sample: {},
  experiment: {},
  data: {}
})

// Helper to check if an object has any non-empty values
const hasNonEmptyData = (obj) => {
  if (!obj || typeof obj !== 'object') return false
  return Object.values(obj).some(val => {
    if (Array.isArray(val)) return val.length > 0
    if (typeof val === 'object' && val !== null) return hasNonEmptyData(val)
    return val !== '' && val !== null && val !== undefined
  })
}

// Check if there's any data to download
const hasData = computed(() => {
  return experimentId.value.trim() !== '' ||
    hasNonEmptyData(experimentData.value.facility) ||
    hasNonEmptyData(experimentData.value.apparatus) ||
    hasNonEmptyData(experimentData.value.daq) ||
    hasNonEmptyData(experimentData.value.sample) ||
    hasNonEmptyData(experimentData.value.experiment) ||
    hasNonEmptyData(experimentData.value.data)
})

// Data for download modal
const downloadData = computed(() => ({
  experiment_id: experimentId.value,
  ...experimentData.value
}))

// Open download modal
const handleDownload = () => {
  showDownloadModal.value = true
}

onMounted(async () => {
  if (!props.ppk) {
    error.value = 'Project ID is required'
    loading.value = false
    return
  }

  try {
    // Get project info
    const project = await projectService.get(props.ppk)
    projectName.value = project.name
  } catch (err) {
    error.value = 'Failed to load project'
    console.error(err)
  } finally {
    loading.value = false
  }
})

const handleOpenSection = (section) => {
  activeSection.value = section
}

const getSectionData = (section) => {
  if (section === 'facilityApparatus') {
    return {
      facility: experimentData.value.facility,
      apparatus: experimentData.value.apparatus
    }
  }
  if (section === 'protocol') {
    return experimentData.value.experiment?.protocol || []
  }
  return experimentData.value[section] || {}
}

// Get selected features from experimental setup for protocol step dropdown
const getSelectedFeatures = () => {
  // Features are stored in experiment.features (from experimental setup form)
  const features = experimentData.value.experiment?.features
  if (Array.isArray(features) && features.length > 0) {
    return features
  }
  return []
}

const handleSectionSave = (section, data) => {
  if (section === 'facilityApparatus') {
    experimentData.value.facility = data.facility || {}
    experimentData.value.apparatus = data.apparatus || {}
  } else if (section === 'protocol') {
    if (!experimentData.value.experiment) {
      experimentData.value.experiment = {}
    }
    experimentData.value.experiment.protocol = data
  } else {
    experimentData.value[section] = data
  }
  activeSection.value = null
}

const handleSave = async () => {
  saving.value = true

  try {
    const result = await experimentService.create(props.ppk, {
      experiment_id: experimentId.value,
      data: experimentData.value
    })

    // Navigate to view the new experiment
    router.push(`/view_experiment?e=${result.pkey}`)
  } catch (err) {
    console.error('Failed to save experiment:', err)
    alert('Failed to save experiment. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.download-btn {
  background-color: var(--strabo-bg-tertiary, #374151);
  border: 1px solid var(--strabo-border, #4b5563);
  border-radius: 0.375rem;
  padding: 0.5rem;
  color: var(--strabo-text-secondary);
  cursor: pointer;
  transition: all 0.2s;
}

.download-btn:hover {
  background-color: var(--strabo-bg-secondary);
  color: var(--strabo-text-primary);
}

.page-title {
  font-size: 1.75rem;
  font-weight: 600;
  color: var(--strabo-text-primary);
  margin-bottom: 0.25rem;
}

.page-subtitle {
  font-size: 1rem;
  color: var(--strabo-text-secondary);
}

.form-section {
  background-color: var(--strabo-bg-secondary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.5rem;
  padding: 1rem;
}

.form-label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: var(--strabo-text-primary);
}
</style>
