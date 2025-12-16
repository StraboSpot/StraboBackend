<template>
  <div>
    <PageHeader title="New Project" back-link="/" />

    <div class="card max-w-2xl mx-auto">
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
          <router-link to="/" class="btn-secondary">Cancel</router-link>
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ saving ? 'Creating...' : 'Create Project' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { projectService } from '@/services/api'

const router = useRouter()

const form = ref({
  name: '',
  description: ''
})

const saving = ref(false)

async function handleSubmit() {
  if (!form.value.name.trim()) return

  saving.value = true
  try {
    await projectService.create(form.value)
    // Redirect to my_experimental_data after creation
    window.location.href = '/my_experimental_data'
  } catch (error) {
    console.error('Failed to create project:', error)
    alert('Failed to create project. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>
