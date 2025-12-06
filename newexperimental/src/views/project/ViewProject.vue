<template>
  <div>
    <PageHeader :title="project?.name || 'Project'" back-link="/my_experimental_data" />

    <div v-if="loading" class="text-center py-8">
      <p class="text-strabo-text-secondary">Loading project...</p>
    </div>

    <div v-else-if="project" class="max-w-4xl mx-auto">
      <!-- Project Info -->
      <div class="card mb-6">
        <div class="flex justify-between items-start mb-4">
          <div>
            <h2 class="text-xl font-semibold text-strabo-text-primary">{{ project.name }}</h2>
            <p v-if="project.description" class="text-strabo-text-secondary mt-2">
              {{ project.description }}
            </p>
          </div>
          <div class="flex gap-2">
            <router-link :to="`/edit_project?ppk=${ppk}`" class="btn-secondary">Edit</router-link>
            <router-link :to="`/add_experiment?ppk=${ppk}`" class="btn-primary">Add Experiment</router-link>
          </div>
        </div>
      </div>

      <!-- Experiments List -->
      <div class="card">
        <h3 class="section-header">Experiments</h3>

        <div v-if="experiments.length === 0" class="text-center py-8 text-strabo-text-secondary">
          No experiments yet. Click "Add Experiment" to create one.
        </div>

        <table v-else class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Apparatus Type</th>
              <th>Last Modified</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="exp in experiments" :key="exp.pkey">
              <td>{{ exp.id || 'N/A' }}</td>
              <td>{{ exp.apparatus_type || 'N/A' }}</td>
              <td>{{ exp.modified_timestamp }}</td>
              <td>
                <div class="flex gap-2">
                  <router-link :to="`/view_experiment?e=${exp.pkey}`" class="text-strabo-accent hover:underline">
                    View
                  </router-link>
                  <router-link :to="`/edit_experiment?e=${exp.pkey}`" class="text-strabo-accent hover:underline">
                    Edit
                  </router-link>
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
import { ref, onMounted } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import { projectService } from '@/services/api'

const props = defineProps({
  ppk: String
})

const project = ref(null)
const experiments = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const data = await projectService.get(props.ppk)
    project.value = data
    experiments.value = data.experiments || []
  } catch (error) {
    console.error('Failed to load project:', error)
  } finally {
    loading.value = false
  }
})
</script>
