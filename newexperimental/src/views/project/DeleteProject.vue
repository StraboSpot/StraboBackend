<template>
  <div>
    <PageHeader title="Delete Project" :back-link="`/view_project?ppk=${ppk}`" />

    <div v-if="loading" class="text-center py-8">
      <p class="text-strabo-text-secondary">Loading project...</p>
    </div>

    <div v-else-if="error" class="text-center py-8">
      <p class="text-red-400">{{ error }}</p>
      <a href="/my_experimental_data" class="btn-secondary mt-4 inline-block">Back to Projects</a>
    </div>

    <div v-else-if="project" class="card max-w-2xl mx-auto">
      <div class="text-center mb-6">
        <div class="text-red-500 text-5xl mb-4">!</div>
        <h2 class="text-xl font-semibold text-strabo-text-primary mb-2">
          Delete "{{ project.name }}"?
        </h2>
        <p class="text-strabo-text-secondary">
          This action cannot be undone. The project and all its experiments will be permanently deleted.
        </p>
      </div>

      <div v-if="project.experiments && project.experiments.length > 0" class="mb-6 p-4 bg-strabo-surface rounded-lg">
        <p class="text-strabo-text-secondary text-sm mb-2">
          This project contains <strong class="text-strabo-text-primary">{{ project.experiments.length }}</strong>
          experiment{{ project.experiments.length === 1 ? '' : 's' }} that will also be deleted:
        </p>
        <ul class="text-sm text-strabo-text-secondary list-disc list-inside">
          <li v-for="exp in project.experiments.slice(0, 5)" :key="exp.pkey">
            {{ exp.id || `EXP-${exp.pkey}` }}
            <span v-if="exp.apparatus_type"> ({{ exp.apparatus_type }})</span>
          </li>
          <li v-if="project.experiments.length > 5">
            ... and {{ project.experiments.length - 5 }} more
          </li>
        </ul>
      </div>

      <div class="flex justify-center gap-4">
        <router-link :to="`/view_project?ppk=${ppk}`" class="btn-secondary">
          Cancel
        </router-link>
        <button
          @click="deleteProject"
          class="btn-danger"
          :disabled="deleting"
        >
          {{ deleting ? 'Deleting...' : 'Delete Project' }}
        </button>
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
const loading = ref(true)
const deleting = ref(false)
const error = ref(null)

onMounted(async () => {
  try {
    const data = await projectService.get(props.ppk)
    if (!data.can_delete) {
      error.value = 'You do not have permission to delete this project.'
      return
    }
    project.value = data
  } catch (err) {
    console.error('Failed to load project:', err)
    error.value = 'Failed to load project. It may not exist or you may not have access.'
  } finally {
    loading.value = false
  }
})

async function deleteProject() {
  if (deleting.value) return

  deleting.value = true
  try {
    await projectService.delete(props.ppk)
    // Redirect to my_experimental_data after deletion
    window.location.href = '/my_experimental_data'
  } catch (err) {
    console.error('Failed to delete project:', err)
    alert('Failed to delete project. Please try again.')
    deleting.value = false
  }
}
</script>

<style scoped>
.btn-danger {
  @apply px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}
</style>
