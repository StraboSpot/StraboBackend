<template>
  <div>
    <PageHeader title="Edit Project" back-link="/my_experimental_data" />

    <div v-if="loading" class="text-center py-8">
      <p class="text-strabo-text-secondary">Loading project...</p>
    </div>

    <div v-else-if="error" class="max-w-2xl mx-auto">
      <div class="alert-error">
        {{ error }}
      </div>
      <div class="mt-4">
        <router-link to="/my_experimental_data" class="btn-secondary">
          ← Back to My Projects
        </router-link>
      </div>
    </div>

    <div v-else class="card max-w-2xl mx-auto">
      <form @submit.prevent="handleSubmit">
        <div class="mb-4">
          <label for="name" class="form-label">Project Name *</label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            class="form-input"
            required
            placeholder="Enter project name"
          />
        </div>

        <div class="mb-6">
          <label for="description" class="form-label">Description</label>
          <textarea
            id="description"
            v-model="form.description"
            class="form-textarea"
            placeholder="Project description (optional)"
            rows="4"
          ></textarea>
        </div>

        <div class="flex justify-end gap-3">
          <router-link :to="`/view_project?ppk=${ppk}`" class="btn-secondary">Cancel</router-link>
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </form>
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

const form = ref({
  name: '',
  description: ''
})

const loading = ref(true)
const saving = ref(false)
const error = ref(null)

onMounted(async () => {
  try {
    const project = await projectService.get(props.ppk)

    // Check if user can edit this project
    if (!project.can_edit) {
      error.value = 'You do not have permission to edit this project.'
      return
    }

    form.value.name = project.name || ''
    form.value.description = project.description || ''
  } catch (err) {
    console.error('Failed to load project:', err)
    error.value = 'Project not found or you do not have permission to access it.'
  } finally {
    loading.value = false
  }
})

async function handleSubmit() {
  if (!form.value.name.trim()) return

  saving.value = true
  try {
    await projectService.update(props.ppk, form.value)
    window.location.href = '/my_experimental_data'
  } catch (err) {
    console.error('Failed to update project:', err)
    alert('Failed to save changes. You may not have permission to edit this project.')
  } finally {
    saving.value = false
  }
}
</script>
