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
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
        <label
          v-for="feature in APPARATUS_FEATURES"
          :key="feature"
          class="feature-label"
        >
          <input
            type="checkbox"
            :value="feature"
            v-model="form.features"
            class="feature-checkbox"
          />
          <span>{{ feature }}</span>
        </label>
      </div>
    </CollapsibleSection>

    <!-- Parameters -->
    <CollapsibleSection title="Parameters" class="mt-4">
      <p class="text-sm text-strabo-text-secondary mb-4">
        Define the operational parameters and limits of this apparatus.
      </p>

      <div v-if="form.parameters.length > 0" class="space-y-4 mb-4">
        <div
          v-for="(param, idx) in form.parameters"
          :key="idx"
          class="p-4 bg-strabo-bg-tertiary rounded"
        >
          <div class="flex justify-between items-start mb-3">
            <span class="text-sm font-medium text-strabo-text-primary">Parameter {{ idx + 1 }}</span>
            <button
              type="button"
              @click="removeParameter(idx)"
              class="text-strabo-error hover:underline text-sm"
            >
              Remove
            </button>
          </div>
          <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="col-span-2 md:col-span-1">
              <label class="form-label text-xs">Type</label>
              <select v-model="param.type" class="form-select text-sm">
                <option value="">Select...</option>
                <option v-for="t in PARAMETER_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div>
              <label class="form-label text-xs">Min</label>
              <input v-model="param.min" type="text" class="form-input text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Max</label>
              <input v-model="param.max" type="text" class="form-input text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Unit</label>
              <select v-model="param.unit" class="form-select text-sm">
                <option value="">Select...</option>
                <option v-for="u in UNIT_TYPES" :key="u" :value="u">{{ u }}</option>
              </select>
            </div>
            <div>
              <label class="form-label text-xs">Prefix</label>
              <select v-model="param.prefix" class="form-select text-sm">
                <option value="">Select...</option>
                <option v-for="p in UNIT_PREFIXES" :key="p" :value="p">{{ p }}</option>
              </select>
            </div>
            <div class="col-span-2 md:col-span-5">
              <label class="form-label text-xs">Note</label>
              <input v-model="param.note" type="text" class="form-input text-sm" placeholder="Optional note" />
            </div>
          </div>
        </div>
      </div>

      <button
        type="button"
        @click="addParameter"
        class="btn-secondary text-sm"
      >
        + Add Parameter
      </button>
    </CollapsibleSection>

    <!-- Documents (placeholder for now) -->
    <CollapsibleSection title="Documents" class="mt-4">
      <p class="text-sm text-strabo-text-secondary mb-4">
        Upload manuals, diagrams, or other documentation.
      </p>
      <div class="text-center py-8 border-2 border-dashed border-strabo-border rounded">
        <p class="text-strabo-text-secondary">Document upload coming soon</p>
      </div>
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
import {
  APPARATUS_TYPES,
  APPARATUS_FEATURES,
  PARAMETER_TYPES,
  UNIT_TYPES,
  UNIT_PREFIXES
} from '@/schemas/laps-enums'

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

function addParameter() {
  form.value.parameters.push({
    type: '',
    min: '',
    max: '',
    unit: '',
    prefix: '',
    note: ''
  })
}

function removeParameter(idx) {
  form.value.parameters.splice(idx, 1)
}

function handleSubmit() {
  if (!isValid.value) return
  emit('submit', form.value)
}
</script>

<style>
/* Use non-scoped style to override site CSS with higher specificity */
.feature-label {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  padding: 8px !important;
  border-radius: 4px !important;
  cursor: pointer !important;
  color: #e0e0e0 !important;
  font-size: 14px !important;
}

.feature-label:hover {
  background-color: #3a3a3a !important;
}

/* Override site's checkbox hiding */
.feature-checkbox {
  width: 18px !important;
  height: 18px !important;
  min-width: 18px !important;
  opacity: 1 !important;
  position: relative !important;
  z-index: 1 !important;
  appearance: auto !important;
  -webkit-appearance: checkbox !important;
  -moz-appearance: checkbox !important;
  -ms-appearance: auto !important;
  display: inline-block !important;
  float: none !important;
  margin: 0 !important;
  margin-right: 0 !important;
  accent-color: #f4511e;
  cursor: pointer !important;
}
</style>
