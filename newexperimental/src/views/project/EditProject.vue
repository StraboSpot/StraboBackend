<template>
  <div>
    <PageHeader title="Edit Project" :back-link="`/view_project?ppk=${ppk}`" />

    <div v-if="loading" class="text-center py-8">
      <p class="text-strabo-text-secondary">Loading project...</p>
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

onMounted(async () => {
  try {
    const project = await projectService.get(props.ppk)
    form.value.name = project.name || ''
    form.value.description = project.description || ''
  } catch (error) {
    console.error('Failed to load project:', error)
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
  } catch (error) {
    console.error('Failed to update project:', error)
    alert('Failed to save changes. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
