<template>
  <form @submit.prevent="handleSubmit" class="max-w-4xl">
    <!-- Basic Information -->
    <CollapsibleSection title="Basic Information" :default-open="true">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="name" class="form-label">Apparatus Name *</label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            class="form-input"
            required
            placeholder="e.g., Triaxial Testing System"
          />
        </div>

        <div>
          <label for="type" class="form-label">Apparatus Type *</label>
          <select id="type" v-model="form.type" class="form-select" required>
            <option value="">Select type...</option>
            <option v-for="t in APPARATUS_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <div>
          <label for="location" class="form-label">Location</label>
          <input
            id="location"
            v-model="form.location"
            type="text"
            class="form-input"
            placeholder="Room number or lab location"
          />
        </div>

        <div>
          <label for="apparatusId" class="form-label">Apparatus ID</label>
          <input
            id="apparatusId"
            v-model="form.id"
            type="text"
            class="form-input"
            placeholder="Internal ID or serial number"
          />
        </div>

        <div class="md:col-span-2">
          <label for="description" class="form-label">Description</label>
          <textarea
            id="description"
            v-model="form.description"
            class="form-textarea"
            rows="3"
            placeholder="Brief description of the apparatus..."
          ></textarea>
        </div>
      </div>
    </CollapsibleSection>

    <!-- Features -->
    <CollapsibleSection title="Features" class="mt-4">
      <p class="text-sm text-strabo-text-secondary mb-4">
        Select all features that apply to this apparatus.
      </p>
      <FeaturePills
        :features="APPARATUS_FEATURES"
        v-model="form.features"
      />
    </CollapsibleSection>

    <!-- Parameters -->
    <CollapsibleSection title="Parameters" class="mt-4">
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
    </CollapsibleSection>

    <!-- Documents -->
    <CollapsibleSection title="Documents" class="mt-4">
      <p class="text-sm text-strabo-text-secondary mb-4">
        Upload manuals, diagrams, or other documentation.
      </p>
      <DocumentsEditor
        v-model="form.documents"
        add-label="Add Document"
        :show-upload="true"
      />
    </CollapsibleSection>

    <!-- Actions -->
    <div class="flex justify-end gap-3 mt-6">
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
import CollapsibleSection from '@/components/CollapsibleSection.vue'
import FeaturePills from '@/components/FeaturePills.vue'
import ParametersEditor from '@/components/ParametersEditor.vue'
import DocumentsEditor from '@/components/DocumentsEditor.vue'
import {
  APPARATUS_TYPES,
  APPARATUS_FEATURES
} from '@/schemas/laps-enums'

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

const isValid = computed(() => {
  return form.value.name.trim().length > 0 && form.value.type.length > 0
})

function handleSubmit() {
  if (!isValid.value) return
  emit('submit', form.value)
}
</script>
