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
          <label for="experimentId" class="form-label">Experiment ID<span class="text-red-500">*</span></label>
          <InputText
            id="experimentId"
            v-model="experimentId"
            placeholder="Enter experiment identifier..."
            class="w-full"
          />
        </div>
      </div>

      <!-- Bulk Load Bar -->
      <BulkLoadBar
        mode="add"
        @load-from-previous="showChooseExperimentModal = true; chooseExperimentDataType = 'all'"
        @load-example="handleLoadExample"
        @load-from-json="handleLoadFromJson"
      />

      <!-- Section Tiles -->
      <ExperimentTiles
        :experiment-data="experimentData"
        @open-section="handleOpenSection"
      />

      <!-- Action Buttons -->
      <div class="flex justify-center gap-4 mt-8 mb-8">
        <a href="/my_experimental_data.php" class="btn-secondary">
          Cancel
        </a>
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
        @load-from-previous="handleSectionLoadFromPrevious"
        @load-from-json="handleSectionLoadFromJson"
        @load-from-repository="showChooseApparatusModal = true"
        @clear="handleSectionClear"
      />

      <!-- Download Modal -->
      <DownloadModal
        :visible="showDownloadModal"
        :data="downloadData"
        :filename="experimentId"
        @close="showDownloadModal = false"
      />

      <!-- Choose Experiment Modal (for bulk load from previous) -->
      <ChooseExperimentModal
        :visible="showChooseExperimentModal"
        :data-type="chooseExperimentDataType"
        @close="showChooseExperimentModal = false"
        @select="handleExperimentSelected"
      />

      <!-- Choose Apparatus Modal (for loading from repository) -->
      <ChooseApparatusModal
        :visible="showChooseApparatusModal"
        @close="showChooseApparatusModal = false"
        @select="handleApparatusSelected"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import ExperimentTiles from '@/components/ExperimentTiles.vue'
import SectionModal from '@/components/SectionModal.vue'
import DownloadModal from '@/components/DownloadModal.vue'
import BulkLoadBar from '@/components/BulkLoadBar.vue'
import ChooseExperimentModal from '@/components/ChooseExperimentModal.vue'
import ChooseApparatusModal from '@/components/ChooseApparatusModal.vue'
import { projectService, experimentService, bulkLoadService } from '@/services/api'

const props = defineProps({
  ppk: String
})

const router = useRouter()
const toast = useToast()

const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const projectName = ref('')
const experimentId = ref('')
const activeSection = ref(null)
const showDownloadModal = ref(false)

// Bulk load modal states
const showChooseExperimentModal = ref(false)
const showChooseApparatusModal = ref(false)
const chooseExperimentDataType = ref('all')

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

// Validate experiment before saving
const validateExperiment = () => {
  const errors = []

  // Experiment ID is required
  if (!experimentId.value || experimentId.value.trim() === '') {
    errors.push('Experiment ID cannot be blank.')
  }

  // Facility/Apparatus info is required
  const hasFacilityData = experimentData.value.facility &&
    (experimentData.value.facility.name || experimentData.value.facility.type)
  const hasApparatusData = experimentData.value.apparatus &&
    (experimentData.value.apparatus.name || experimentData.value.apparatus.type)

  if (!hasFacilityData && !hasApparatusData) {
    errors.push('Facility & Apparatus info cannot be blank.')
  }

  return errors
}

const handleSave = async () => {
  // Validate before saving
  const errors = validateExperiment()
  if (errors.length > 0) {
    toast.add({
      severity: 'error',
      summary: 'Validation Error',
      detail: errors.join('\n'),
      life: 5000
    })
    return
  }

  saving.value = true

  try {
    await experimentService.create(props.ppk, {
      experiment_id: experimentId.value,
      data: experimentData.value
    })

    // Show success toast
    toast.add({
      severity: 'success',
      summary: 'Experiment Saved',
      detail: 'Your experiment has been created successfully.',
      life: 2000
    })

    // Redirect to My Experimental Data after brief delay
    setTimeout(() => {
      window.location.href = '/my_experimental_data.php'
    }, 1500)
  } catch (err) {
    console.error('Failed to save experiment:', err)
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to save experiment. Please try again.',
      life: 5000
    })
    saving.value = false
  }
}

// ===== Bulk Load Handlers =====

// Load example data (New User? Load Example Data button)
const handleLoadExample = async () => {
  if (!confirm('Are you sure you want to load example data?\nThis will clear any data already entered in the interface.')) {
    return
  }

  try {
    const data = await bulkLoadService.getExampleData()
    loadExperimentData(data, 'all')
  } catch (err) {
    console.error('Failed to load example data:', err)
    alert('Failed to load example data.')
  }
}

// Load from JSON file (top-level)
const handleLoadFromJson = (data) => {
  loadExperimentData(data, 'all')
}

// Handle experiment selected from ChooseExperimentModal
const handleExperimentSelected = ({ dataType, data }) => {
  loadExperimentData(data, dataType)
}

// Handle apparatus selected from ChooseApparatusModal
const handleApparatusSelected = ({ facility, apparatus }) => {
  experimentData.value.facility = facility || {}
  experimentData.value.apparatus = apparatus || {}
}

// Core function to load experiment data into the form
const loadExperimentData = (inData, dataType) => {
  // Handle nested data structure from API (experiment.data contains LAPS sections)
  // vs flat structure from JSON file (LAPS sections at root)
  const lapsData = inData.data && (inData.data.facility || inData.data.apparatus || inData.data.daq || inData.data.sample || inData.data.experiment || inData.data.data)
    ? inData.data  // API format: { data: { facility: {...}, ... } }
    : inData       // JSON file format: { facility: {...}, ... }

  // Clear appropriate section(s) first
  if (dataType === 'all') {
    clearAllData()
  } else if (dataType === 'facilityApparatus') {
    experimentData.value.facility = {}
    experimentData.value.apparatus = {}
  } else if (dataType === 'daq') {
    experimentData.value.daq = {}
  } else if (dataType === 'sample') {
    experimentData.value.sample = {}
  } else if (dataType === 'experiment' || dataType === 'protocol') {
    experimentData.value.experiment = {}
  } else if (dataType === 'data') {
    experimentData.value.data = {}
  }

  // Load the data based on dataType
  if (dataType === 'facilityApparatus' || dataType === 'all') {
    if (lapsData.facility) experimentData.value.facility = lapsData.facility
    if (lapsData.apparatus) experimentData.value.apparatus = lapsData.apparatus
  }
  if (dataType === 'daq' || dataType === 'all') {
    if (lapsData.daq) experimentData.value.daq = lapsData.daq
  }
  if (dataType === 'sample' || dataType === 'all') {
    if (lapsData.sample) experimentData.value.sample = lapsData.sample
  }
  if (dataType === 'experiment' || dataType === 'protocol' || dataType === 'all') {
    if (lapsData.experiment) experimentData.value.experiment = lapsData.experiment
  }
  if (dataType === 'data' || dataType === 'all') {
    if (lapsData.data) experimentData.value.data = lapsData.data
  }

  // Load experiment ID if loading all data
  if (dataType === 'all' || dataType === 'experiment') {
    // Try multiple locations for experiment_id
    if (inData.experiment_id) {
      experimentId.value = inData.experiment_id
    } else if (lapsData.experiment?.id) {
      experimentId.value = lapsData.experiment.id
    }
  }
}

// Clear all experiment data
const clearAllData = () => {
  experimentData.value = {
    facility: {},
    apparatus: {},
    daq: {},
    sample: {},
    experiment: {},
    data: {}
  }
  experimentId.value = ''
}

// ===== Section-Level Load Handlers (from LoadDataBar in SectionModal) =====

// Handle "from Previous Experiment" click in section modal
const handleSectionLoadFromPrevious = (section) => {
  chooseExperimentDataType.value = section
  showChooseExperimentModal.value = true
}

// Handle "From JSON File" load in section modal
const handleSectionLoadFromJson = (section, data) => {
  loadExperimentData(data, section)
  // Close and reopen modal to refresh with new data
  const currentSection = activeSection.value
  activeSection.value = null
  setTimeout(() => {
    activeSection.value = currentSection
  }, 50)
}

// Handle "Clear Interface" click in section modal
const handleSectionClear = (section) => {
  if (section === 'facilityApparatus') {
    experimentData.value.facility = {}
    experimentData.value.apparatus = {}
  } else if (section === 'protocol') {
    if (experimentData.value.experiment) {
      experimentData.value.experiment.protocol = []
    }
  } else {
    experimentData.value[section] = {}
  }
  // Close and reopen modal to refresh with cleared data
  const currentSection = activeSection.value
  activeSection.value = null
  setTimeout(() => {
    activeSection.value = currentSection
  }, 50)
}

// Expose methods for SectionModal to use for per-section loading
const openChooseExperimentForSection = (dataType) => {
  chooseExperimentDataType.value = dataType
  showChooseExperimentModal.value = true
}

const openChooseApparatusModal = () => {
  showChooseApparatusModal.value = true
}

// Expose to SectionModal via provide/inject or props
defineExpose({
  openChooseExperimentForSection,
  openChooseApparatusModal,
  loadExperimentData
})
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
