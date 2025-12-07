<template>
  <div>
    <PageHeader
      :title="experimentId ? `Experiment: ${experimentId}` : 'View Experiment'"
      :subtitle="projectName ? `Project: ${projectName}` : ''"
      @back="goBack"
    />

    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading experiment...</p>
    </div>

    <div v-else-if="error" class="text-center py-12">
      <p class="text-red-500 mb-4">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary">Back to My Data</a>
    </div>

    <template v-else>
      <!-- Action buttons -->
      <div class="max-w-3xl mx-auto px-4 mb-6">
        <div class="flex justify-end gap-3">
          <button
            v-if="canEdit"
            @click="handleDownload"
            class="btn-secondary flex items-center gap-2"
            title="Download Experiment"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Download
          </button>
          <router-link
            v-if="canEdit"
            :to="`/edit_experiment?e=${e}`"
            class="btn-primary"
          >
            Edit Experiment
          </router-link>
          <router-link
            v-if="canDelete"
            :to="`/delete_experiment?e=${e}`"
            class="btn-danger"
          >
            Delete
          </router-link>
        </div>
      </div>

      <!-- Experiment Info -->
      <div class="max-w-3xl mx-auto px-4 mb-6">
        <div class="info-card">
          <div class="info-row">
            <span class="info-label">Experiment ID:</span>
            <span class="info-value">{{ experimentId || 'Not set' }}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Last Modified:</span>
            <span class="info-value">{{ modifiedDate || 'Unknown' }}</span>
          </div>
        </div>
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
        @close="activeSection = null"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ExperimentTiles from '@/components/ExperimentTiles.vue'
import SectionModal from '@/components/SectionModal.vue'
import { experimentService } from '@/services/api'

const props = defineProps({
  e: String
})

const router = useRouter()

const loading = ref(true)
const error = ref(null)
const experimentId = ref('')
const projectPkey = ref(null)
const projectName = ref('')
const modifiedDate = ref('')
const canEdit = ref(false)
const canDelete = ref(false)
const activeSection = ref(null)

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
    projectPkey.value = experiment.project_pkey
    projectName.value = experiment.project_name || ''
    modifiedDate.value = experiment.modified_date || ''
    canEdit.value = experiment.can_edit
    canDelete.value = experiment.can_delete

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

const handleDownload = () => {
  experimentService.download(props.e)
}

const goBack = () => {
  router.back()
}
</script>

<style scoped>
.info-card {
  background-color: var(--strabo-bg-secondary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.5rem;
  padding: 1rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--strabo-border);
}

.info-row:last-child {
  border-bottom: none;
}

.info-label {
  color: var(--strabo-text-secondary);
  font-weight: 500;
}

.info-value {
  color: var(--strabo-text-primary);
}

.btn-danger {
  background-color: #dc2626;
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  font-weight: 500;
  transition: background-color 0.2s;
}

.btn-danger:hover {
  background-color: #b91c1c;
}
</style>
