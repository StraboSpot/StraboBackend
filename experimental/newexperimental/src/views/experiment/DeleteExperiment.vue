<template>
  <div>
    <PageHeader
      title="Delete Experiment"
      :back-link="`/view_experiment?e=${e}`"
    />

    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading experiment...</p>
    </div>

    <div v-else-if="error" class="text-center py-12">
      <p class="text-red-500 mb-4">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary">Back to My Data</a>
    </div>

    <template v-else>
      <div class="max-w-xl mx-auto px-4 py-8">
        <div class="warning-card">
          <div class="warning-icon">
            <svg class="w-16 h-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>

          <h2 class="text-xl font-bold text-center mb-4">
            Are you sure you want to delete this experiment?
          </h2>

          <div class="experiment-info">
            <div class="info-row">
              <span class="info-label">Experiment ID:</span>
              <span class="info-value">{{ experimentId || 'Not set' }}</span>
            </div>
            <div v-if="projectName" class="info-row">
              <span class="info-label">Project:</span>
              <span class="info-value">{{ projectName }}</span>
            </div>
          </div>

          <p class="text-center text-strabo-text-secondary mb-6">
            This action cannot be undone. All experiment data will be permanently deleted.
          </p>

          <div class="flex justify-center gap-4">
            <router-link :to="`/view_experiment?e=${e}`" class="btn-secondary">
              Cancel
            </router-link>
            <button
              @click="handleDelete"
              :disabled="deleting"
              class="btn-danger"
            >
              {{ deleting ? 'Deleting...' : 'Delete Experiment' }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { experimentService } from '@/services/api'

const props = defineProps({
  e: String
})

const router = useRouter()

const loading = ref(true)
const error = ref(null)
const deleting = ref(false)
const experimentId = ref('')
const projectPkey = ref(null)
const projectName = ref('')

onMounted(async () => {
  if (!props.e) {
    error.value = 'Experiment ID is required'
    loading.value = false
    return
  }

  try {
    const experiment = await experimentService.get(props.e)

    if (!experiment.can_delete) {
      error.value = 'You do not have permission to delete this experiment'
      loading.value = false
      return
    }

    experimentId.value = experiment.experiment_id || ''
    projectPkey.value = experiment.project_pkey
    projectName.value = experiment.project_name || ''
  } catch (err) {
    error.value = 'Failed to load experiment'
    console.error(err)
  } finally {
    loading.value = false
  }
})

const handleDelete = async () => {
  deleting.value = true

  try {
    const result = await experimentService.delete(props.e)

    // Navigate back to project or my data
    if (result.project_pkey) {
      router.push(`/view_project?ppk=${result.project_pkey}`)
    } else {
      window.location.href = '/my_experimental_data'
    }
  } catch (err) {
    console.error('Failed to delete experiment:', err)
    alert('Failed to delete experiment. Please try again.')
    deleting.value = false
  }
}
</script>

<style scoped>
.warning-card {
  background-color: var(--strabo-bg-secondary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.5rem;
  padding: 2rem;
}

.warning-icon {
  display: flex;
  justify-content: center;
  margin-bottom: 1rem;
}

.experiment-info {
  background-color: var(--strabo-bg-primary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.375rem;
  padding: 1rem;
  margin-bottom: 1.5rem;
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

.btn-danger:hover:not(:disabled) {
  background-color: #b91c1c;
}

.btn-danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
