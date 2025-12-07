<template>
  <div>
    <PageHeader
      :title="experimentId ? `Edit: ${experimentId}` : 'Edit Experiment'"
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
      <!-- Experiment ID field -->
      <div class="max-w-3xl mx-auto px-4 mb-6">
        <div class="form-section">
          <label for="experimentId" class="form-label">Experiment ID</label>
          <input
            id="experimentId"
            v-model="experimentId"
            type="text"
            class="form-input"
            placeholder="Enter experiment identifier..."
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
        <router-link :to="`/view_experiment?e=${e}`" class="btn-secondary">
          Cancel
        </router-link>
        <button @click="handleSave" :disabled="saving" class="btn-primary">
          {{ saving ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>

      <!-- Section Modals -->
      <SectionModal
        v-if="activeSection"
        :section="activeSection"
        :data="getSectionData(activeSection)"
        :readonly="false"
        @close="activeSection = null"
        @save="handleSectionSave"
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
const saving = ref(false)
const experimentId = ref('')
const projectPkey = ref(null)
const projectName = ref('')
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

    if (!experiment.can_edit) {
      error.value = 'You do not have permission to edit this experiment'
      loading.value = false
      return
    }

    experimentId.value = experiment.experiment_id || ''
    projectPkey.value = experiment.project_pkey
    projectName.value = experiment.project_name || ''

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
    await experimentService.update(props.e, {
      experiment_id: experimentId.value,
      data: experimentData.value
    })

    // Navigate back to view
    router.push(`/view_experiment?e=${props.e}`)
  } catch (err) {
    console.error('Failed to save experiment:', err)
    alert('Failed to save experiment. Please try again.')
  } finally {
    saving.value = false
  }
}

const goBack = () => {
  router.back()
}
</script>

<style scoped>
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

.form-input {
  width: 100%;
  padding: 0.5rem 0.75rem;
  background-color: var(--strabo-bg-primary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.375rem;
  color: var(--strabo-text-primary);
}

.form-input:focus {
  outline: none;
  border-color: var(--strabo-accent);
  box-shadow: 0 0 0 2px rgba(244, 81, 30, 0.2);
}
</style>
