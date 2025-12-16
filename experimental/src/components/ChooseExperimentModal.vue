<template>
  <Dialog
    :visible="visible"
    modal
    :header="'Choose Experiment'"
    :style="{ width: '800px', maxWidth: '95vw' }"
    :closable="true"
    @update:visible="$emit('close')"
    :pt="{
      header: { class: 'dialog-header' },
      headerTitle: { class: 'text-xl flex-1 text-center' }
    }"
  >
    <div class="experiment-list">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-400">Loading experiments...</p>
      </div>

      <div v-else-if="error" class="text-center py-8">
        <p class="text-red-500">{{ error }}</p>
      </div>

      <div v-else-if="!hasExperiments" class="text-center py-8">
        <p class="text-gray-400">No existing experiments found.</p>
      </div>

      <div v-else>
        <div v-for="project in projectsWithExperiments" :key="project.pkey" class="project-group">
          <h3 class="project-title">Project: {{ project.name }}</h3>
          <div class="experiment-table">
            <table>
              <thead>
                <tr>
                  <th style="width: 100px;"></th>
                  <th>Experiment ID</th>
                  <th>Created</th>
                  <th>Modified</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="exp in project.experiments" :key="exp.pkey">
                  <td>
                    <button
                      class="select-btn"
                      @click="handleSelect(exp.pkey)"
                    >
                      Select
                    </button>
                  </td>
                  <td>{{ exp.id || '(no ID)' }}</td>
                  <td>{{ formatDate(exp.created_timestamp) }}</td>
                  <td>{{ formatDate(exp.modified_timestamp) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </Dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { bulkLoadService, experimentService } from '@/services/api'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  dataType: {
    type: String,
    default: 'all'
    // 'all', 'facilityApparatus', 'daq', 'sample', 'experiment', 'protocol', 'data'
  }
})

const emit = defineEmits(['close', 'select'])

const loading = ref(false)
const error = ref(null)
const projects = ref([])

// Filter to only show projects that have experiments
const projectsWithExperiments = computed(() => {
  return projects.value.filter(p => p.experiments && p.experiments.length > 0)
})

const hasExperiments = computed(() => {
  return projectsWithExperiments.value.length > 0
})

// Load experiments when modal becomes visible
watch(() => props.visible, async (newVal) => {
  if (newVal) {
    await loadExperiments()
  }
})

const loadExperiments = async () => {
  loading.value = true
  error.value = null

  try {
    const data = await bulkLoadService.getMyExperiments()
    projects.value = data.projects || []
  } catch (err) {
    error.value = 'Failed to load experiments'
    console.error(err)
  } finally {
    loading.value = false
  }
}

const handleSelect = async (experimentPkey) => {
  loading.value = true
  error.value = null

  try {
    // Fetch the full experiment data
    const experimentData = await experimentService.get(experimentPkey)

    // Emit the loaded data with the dataType so parent knows which section(s) to update
    emit('select', {
      dataType: props.dataType,
      data: experimentData
    })
    emit('close')
  } catch (err) {
    error.value = 'Failed to load experiment data'
    console.error(err)
  } finally {
    loading.value = false
  }
}

const formatDate = (timestamp) => {
  if (!timestamp) return '-'
  // If already formatted string, return as-is
  if (typeof timestamp === 'string' && timestamp.includes(',')) {
    return timestamp
  }
  // Otherwise format it
  try {
    const date = new Date(timestamp)
    return date.toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    })
  } catch {
    return timestamp
  }
}
</script>

<style scoped>
.experiment-list {
  max-height: 60vh;
  overflow-y: auto;
}

.project-group {
  margin-bottom: 1.5rem;
}

.project-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: #ffffff;
  margin-bottom: 0.75rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--p-surface-600);
}

.experiment-table {
  overflow-x: auto;
}

.experiment-table table {
  width: 100%;
  border-collapse: collapse;
}

.experiment-table th,
.experiment-table td {
  padding: 0.75rem 1rem;
  text-align: left;
  border-bottom: 1px solid var(--p-surface-600);
}

.experiment-table th {
  background-color: var(--p-surface-700);
  color: #d1d5db;
  font-weight: 600;
  font-size: 0.9rem;
}

.experiment-table td {
  color: #ffffff;
  font-size: 0.9rem;
}

.experiment-table tbody tr:hover {
  background-color: var(--p-surface-700);
}

.select-btn {
  padding: 0.375rem 0.75rem;
  background-color: #dc3545;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 0.85rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.select-btn:hover {
  background-color: #c82333;
}
</style>
