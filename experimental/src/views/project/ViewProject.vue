<template>
  <div>
    <PageHeader :title="project?.name || 'Project'" back-link="/my_experimental_data" />

    <div v-if="loading" class="text-center py-8">
      <p class="text-strabo-text-secondary">Loading project...</p>
    </div>

    <div v-else-if="error" class="text-center py-8">
      <p class="text-red-400">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary mt-4 inline-block">Back to Projects</a>
    </div>

    <div v-else-if="project" class="max-w-4xl mx-auto">
      <!-- Project Info -->
      <div class="card mb-6">
        <div class="flex justify-between items-start mb-4">
          <div class="flex-1">
            <h2 class="text-xl font-semibold text-strabo-text-primary">{{ project.name }}</h2>
            <p v-if="project.description" class="text-strabo-text-secondary mt-2">
              {{ project.description }}
            </p>
            <div class="mt-3 text-sm text-strabo-text-secondary">
              <span v-if="project.created_date">Created: {{ project.created_date }}</span>
              <span v-if="project.modified_date" class="ml-4">Last Modified: {{ project.modified_date }}</span>
            </div>
          </div>
          <div v-if="project.can_edit" class="flex gap-2 ml-4">
            <button @click="downloadProject" class="btn-secondary" title="Download as JSON">
              Download
            </button>
            <router-link :to="`/edit_project?ppk=${ppk}`" class="btn-secondary">Edit</router-link>
            <router-link :to="`/delete_project?ppk=${ppk}`" class="btn-danger">Delete</router-link>
          </div>
        </div>
      </div>

      <!-- Add Experiment Button -->
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-medium text-strabo-text-primary">Experiments</h3>
        <router-link v-if="project.can_edit" :to="`/add_experiment?ppk=${ppk}`" class="btn-primary">
          Add Experiment
        </router-link>
      </div>

      <!-- Experiments List -->
      <div class="card">
        <div v-if="experiments.length === 0" class="text-center py-8 text-strabo-text-secondary">
          No experiments yet. Click "Add Experiment" to create one.
        </div>

        <table v-else class="data-table">
          <thead>
            <tr>
              <th>Experiment ID</th>
              <th>Apparatus Type</th>
              <th>Sample ID</th>
              <th>Last Modified</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="exp in experiments" :key="exp.pkey">
              <td>{{ exp.id || `EXP-${exp.pkey}` }}</td>
              <td>{{ exp.apparatus_type || 'N/A' }}</td>
              <td>{{ exp.sample_id || 'N/A' }}</td>
              <td>{{ exp.modified_date }}</td>
              <td>
                <div class="flex gap-2">
                  <router-link :to="`/view_experiment?e=${exp.pkey}`" class="text-strabo-accent hover:underline">
                    View
                  </router-link>
                  <router-link v-if="project.can_edit" :to="`/edit_experiment?e=${exp.pkey}`" class="text-strabo-accent hover:underline">
                    Edit
                  </router-link>
                  <a :href="`/newexperimental/api/download_experiment.php?id=${exp.pkey}`" class="text-strabo-accent hover:underline">
                    Download
                  </a>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import { projectService } from '@/services/api'

const props = defineProps({
  ppk: String
})

const project = ref(null)
const loading = ref(true)
const error = ref(null)

const experiments = computed(() => project.value?.experiments || [])

onMounted(async () => {
  try {
    const data = await projectService.get(props.ppk)
    project.value = data
  } catch (err) {
    console.error('Failed to load project:', err)
    error.value = 'Failed to load project. It may not exist or you may not have access.'
  } finally {
    loading.value = false
  }
})

function downloadProject() {
  projectService.download(props.ppk)
}
</script>

<style scoped>
.btn-danger {
  @apply px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md transition-colors;
}
</style>
