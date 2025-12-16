<template>
  <form @submit.prevent="handleSubmit" class="apparatus-form">
    <!-- Basic Information -->
    <fieldset class="form-section">
      <legend>BASIC INFORMATION</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Apparatus Name *</label>
          <input
            v-model="form.name"
            type="text"
            class="form-input"
            placeholder="e.g., Triaxial Testing System"
          />
        </div>

        <div class="field">
          <label class="text-sm">Apparatus Type *</label>
          <select v-model="form.type" class="form-select">
            <option value="">Select type...</option>
            <option v-for="t in APPARATUS_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <div class="field">
          <label class="text-sm">Location</label>
          <input
            v-model="form.location"
            type="text"
            class="form-input"
            placeholder="Room number or lab location"
          />
        </div>

        <div class="field">
          <label class="text-sm">Apparatus ID</label>
          <input
            v-model="form.id"
            type="text"
            class="form-input"
            placeholder="Internal ID or serial number"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Description</label>
          <textarea
            v-model="form.description"
            class="form-textarea"
            rows="2"
            placeholder="Brief description of the apparatus..."
          ></textarea>
        </div>
      </div>
    </fieldset>

    <!-- Features -->
    <fieldset class="form-section">
      <legend>FEATURES</legend>
      <p class="text-sm text-strabo-text-secondary mb-4">
        Select all features that apply to this apparatus.
      </p>
      <FeatureSelector v-model="form.features" />
    </fieldset>

    <!-- Parameters -->
    <fieldset class="form-section">
      <legend>PARAMETERS</legend>
      <p class="text-sm text-strabo-text-secondary mb-4">
        Define the operational parameters and limits of this apparatus.
      </p>
      <ParametersEditor
        v-model="form.parameters"
        add-label="Add Parameter"
        :name-options="APPARATUS_PARAMETER_TYPES"
        :show-min="true"
        :show-max="true"
      />
    </fieldset>

    <!-- Documents -->
    <fieldset class="form-section">
      <legend>DOCUMENTS</legend>
      <p class="text-sm text-strabo-text-secondary mb-4">
        Upload manuals, diagrams, or other documentation.
      </p>
      <DocumentsEditor
        v-model="form.documents"
        add-label="Add Document"
        :show-upload="true"
      />
    </fieldset>

    <!-- Actions -->
    <div class="flex justify-center gap-3 mt-6">
      <button type="button" class="btn-secondary" @click="$emit('cancel')">
        Cancel
      </button>
      <button type="submit" class="btn-primary" :disabled="saving || !isValid">
        {{ saving ? 'Saving...' : submitLabel }}
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import FeatureSelector from '@/components/FeatureSelector.vue'
import ParametersEditor from '@/components/ParametersEditor.vue'
import DocumentsEditor from '@/components/DocumentsEditor.vue'
import {
  APPARATUS_TYPES
} from '@/schemas/laps-enums'

const toast = useToast()

// Apparatus-specific parameter types (from LAPS schema)
const APPARATUS_PARAMETER_TYPES = [
  'Confining Pressure',
  'Effective Pressure',
  'Pore Pressure',
  'Temperature',
  'σ1-Displacement',
  'σ2-Displacement',
  'σ3-Displacement',
  'σ1-Load',
  'σ2-Load',
  'σ3-Load',
  'Displacement Rate',
  'Loading Rate',
  'Stiffness',
  'Sample Diameter',
  'Sample Length',
  'Permeability'
]

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  },
  saving: {
    type: Boolean,
    default: false
  },
  submitLabel: {
    type: String,
    default: 'Save Apparatus'
  }
})

const emit = defineEmits(['submit', 'cancel'])

const form = ref({
  name: '',
  type: '',
  location: '',
  id: '',
  description: '',
  features: [],
  parameters: [],
  documents: []
})

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && data.name) {
    form.value = {
      name: data.name || '',
      type: data.type || '',
      location: data.location || '',
      id: data.id || '',
      description: data.description || '',
      features: Array.isArray(data.features) ? [...data.features] : [],
      parameters: data.parameters?.map(p => ({ ...p })) || [],
      documents: data.documents?.map(d => ({ ...d })) || []
    }
  }
}, { immediate: true, deep: true })

// Validate form and return array of error messages
const validateForm = () => {
  const errors = []
  if (!form.value.name || form.value.name.trim() === '') {
    errors.push('Apparatus Name cannot be blank.')
  }
  if (!form.value.type || form.value.type.trim() === '') {
    errors.push('Apparatus Type cannot be blank.')
  }
  return errors
}

const isValid = computed(() => {
  return validateForm().length === 0
})

function handleSubmit() {
  const errors = validateForm()
  if (errors.length > 0) {
    toast.add({
      severity: 'error',
      summary: 'Validation Error',
      detail: errors.join('\n'),
      life: 5000
    })
    return
  }
  emit('submit', form.value)
}
</script>

<style scoped>
.apparatus-form {
  width: 100%;
}

.form-section {
  border: 1px solid #525252;
  border-radius: 4px;
  padding: 1rem 1.25rem;
  margin-bottom: 1rem;
}

.form-section legend {
  font-size: 0.875rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: #9e9e9e;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}
</style>
