<template>
  <div>
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading experiment...</p>
    </div>

    <div v-else-if="error" class="text-center py-12">
      <p class="text-red-500 mb-4">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary">Back to My Data</a>
    </div>

    <template v-else>
      <!-- Action buttons (top right) -->
      <div class="flex justify-end px-4 mb-4 gap-2">
        <a
          v-if="experimentUuid"
          :href="`/experimental/overview_experiment.php?u=${experimentUuid}`"
          target="_blank"
          class="download-btn"
          title="Overview"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
        </a>
        <button
          @click="handleDownload"
          class="download-btn"
          title="Download Experiment"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
        </button>
      </div>

      <!-- Experiment Header -->
      <div class="text-center mb-6">
        <h1 class="experiment-title">{{ experimentId || 'Untitled Experiment' }}</h1>
        <p class="experiment-meta">
          <span class="meta-label">Last Modified:</span>
          <span class="meta-value">{{ modifiedDate || 'Unknown' }}</span>
        </p>
      </div>

      <!-- Section Tiles (readonly view) -->
      <ExperimentTiles
        :experiment-data="experimentData"
        @open-section="handleOpenSection"
      />

      <!-- Section Modals (readonly) -->
      <SectionModal
        v-if="activeSection"
        :section="activeSection"
        :data="getSectionData(activeSection)"
        :readonly="true"
        :sample-link-owner="ownerPkey"
        @close="activeSection = null"
      />

      <!-- Download Modal -->
      <DownloadModal
        :visible="showDownloadModal"
        :data="downloadData"
        :filename="experimentId"
        :experiment-pkey="props.e"
        @close="showDownloadModal = false"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import ExperimentTiles from '@/components/ExperimentTiles.vue'
import SectionModal from '@/components/SectionModal.vue'
import DownloadModal from '@/components/DownloadModal.vue'
import { experimentService } from '@/services/api'

const props = defineProps({
  e: String
})

const loading = ref(true)
const error = ref(null)
const experimentId = ref('')
const experimentUuid = ref('')
const ownerPkey = ref(null)
const modifiedDate = ref('')
const activeSection = ref(null)
const showDownloadModal = ref(false)

const experimentData = ref({
  facility: {},
  apparatus: {},
  daq: {},
  sample: {},
  experiment: {},
  data: {}
})

onMounted(async () => {
  if (!props.e) {
    error.value = 'Experiment ID is required'
    loading.value = false
    return
  }

  try {
    const experiment = await experimentService.get(props.e)
    experimentId.value = experiment.experiment_id || ''
    experimentUuid.value = experiment.uuid || ''
    ownerPkey.value = experiment.owner_pkey || null
    modifiedDate.value = experiment.modified_date || ''

    // Load LAPS data
    if (experiment.data) {
      experimentData.value = {
        facility: experiment.data.facility || {},
        apparatus: experiment.data.apparatus || {},
        daq: experiment.data.daq || {},
        sample: experiment.data.sample || {},
        experiment: experiment.data.experiment || {},
        data: experiment.data.data || {}
      }
    }
  } catch (err) {
    error.value = 'Failed to load experiment'
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

// Data for download modal
const downloadData = computed(() => ({
  experiment_id: experimentId.value,
  ...experimentData.value
}))

// Open download modal
const handleDownload = () => {
  showDownloadModal.value = true
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

.experiment-title {
  font-size: 1.75rem;
  font-weight: 400;
  color: #9ca3af; /* gray-400 for muted look */
  margin-bottom: 0.5rem;
}

.experiment-meta {
  color: var(--strabo-text-secondary);
  font-size: 0.875rem;
}

.meta-label {
  margin-right: 0.5rem;
}

.meta-value {
  color: #9ca3af;
}
</style>
