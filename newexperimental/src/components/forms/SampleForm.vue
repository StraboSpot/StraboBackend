<template>
  <form @submit.prevent="handleSubmit" class="sample-form">
    <!-- Basic Information -->
    <CollapsibleSection title="Basic Information" :default-open="true">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="sampleName" class="form-label">Sample Name *</label>
          <input
            id="sampleName"
            v-model="form.name"
            type="text"
            class="form-input"
            required
            placeholder="e.g., Carrara Marble #1"
          />
        </div>

        <div>
          <label for="sampleId" class="form-label">Sample ID</label>
          <input
            id="sampleId"
            v-model="form.id"
            type="text"
            class="form-input"
            placeholder="Internal sample identifier"
          />
        </div>

        <div class="md:col-span-2">
          <label for="description" class="form-label">Description</label>
          <textarea
            id="description"
            v-model="form.description"
            class="form-textarea"
            rows="3"
            placeholder="Brief description of the sample..."
          ></textarea>
        </div>
      </div>
    </CollapsibleSection>

    <!-- Material -->
    <CollapsibleSection title="Material" class="mt-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="materialType" class="form-label">Material Type</label>
          <select id="materialType" v-model="form.material.material" class="form-select">
            <option value="">Select type...</option>
            <option v-for="t in MATERIAL_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <div>
          <label for="lithology" class="form-label">Lithology</label>
          <select id="lithology" v-model="form.material.lithology" class="form-select">
            <option value="">Select lithology...</option>
            <option v-for="l in LITHOLOGY_TYPES" :key="l" :value="l">{{ l }}</option>
          </select>
        </div>
      </div>

      <!-- Mineralogy -->
      <div class="mt-6">
        <h4 class="text-sm font-semibold text-strabo-text-primary mb-3">Mineralogy / Composition</h4>
        <p class="text-xs text-strabo-text-secondary mb-3">
          Define the mineral phases and their proportions in this sample.
        </p>

        <div v-if="form.material.composition.length > 0" class="space-y-3 mb-4">
          <div
            v-for="(phase, idx) in form.material.composition"
            :key="idx"
            class="p-3 bg-strabo-bg-tertiary rounded"
          >
            <div class="flex justify-between items-start mb-2">
              <span class="text-xs font-medium text-strabo-text-secondary">Phase {{ idx + 1 }}</span>
              <button
                type="button"
                @click="removePhase(idx)"
                class="text-strabo-error hover:underline text-xs"
              >
                Remove
              </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div>
                <label class="form-label text-xs">Mineral</label>
                <select v-model="phase.mineral" class="form-select text-sm">
                  <option value="">Select...</option>
                  <option v-for="m in MINERAL_TYPES" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div>
                <label class="form-label text-xs">Fraction</label>
                <input v-model="phase.fraction" type="text" class="form-input text-sm" placeholder="e.g., 45" />
              </div>
              <div>
                <label class="form-label text-xs">Unit</label>
                <select v-model="phase.unit" class="form-select text-sm">
                  <option value="">Select...</option>
                  <option v-for="u in FRACTION_UNITS" :key="u" :value="u">{{ u }}</option>
                </select>
              </div>
              <div>
                <label class="form-label text-xs">Grain Size (um)</label>
                <input v-model="phase.grainsize" type="number" class="form-input text-sm" placeholder="e.g., 100" />
              </div>
            </div>
          </div>
        </div>

        <button
          type="button"
          @click="addPhase"
          class="btn-secondary text-sm"
        >
          + Add Mineral Phase
        </button>
      </div>

      <!-- Provenance -->
      <div class="mt-6">
        <h4 class="text-sm font-semibold text-strabo-text-primary mb-3">Provenance</h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label for="provLocation" class="form-label text-xs">Location Name</label>
            <input
              id="provLocation"
              v-model="form.material.provenance.location"
              type="text"
              class="form-input"
              placeholder="e.g., Carrara, Italy"
            />
          </div>
          <div>
            <label for="provLatitude" class="form-label text-xs">Latitude</label>
            <input
              id="provLatitude"
              v-model="form.material.provenance.latitude"
              type="text"
              class="form-input"
              placeholder="e.g., 44.0775"
            />
          </div>
          <div>
            <label for="provLongitude" class="form-label text-xs">Longitude</label>
            <input
              id="provLongitude"
              v-model="form.material.provenance.longitude"
              type="text"
              class="form-input"
              placeholder="e.g., 10.1308"
            />
          </div>
          <div class="md:col-span-3">
            <label for="provNote" class="form-label text-xs">Notes</label>
            <input
              id="provNote"
              v-model="form.material.provenance.note"
              type="text"
              class="form-input"
              placeholder="Additional provenance information..."
            />
          </div>
        </div>
      </div>

      <!-- Texture -->
      <div class="mt-6">
        <h4 class="text-sm font-semibold text-strabo-text-primary mb-3">Texture</h4>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label for="bedding" class="form-label text-xs">Bedding</label>
            <input
              id="bedding"
              v-model="form.material.texture.bedding"
              type="text"
              class="form-input"
              placeholder="e.g., Horizontal"
            />
          </div>
          <div>
            <label for="lineation" class="form-label text-xs">Lineation</label>
            <input
              id="lineation"
              v-model="form.material.texture.lineation"
              type="text"
              class="form-input"
              placeholder="e.g., Weak"
            />
          </div>
          <div>
            <label for="foliation" class="form-label text-xs">Foliation</label>
            <input
              id="foliation"
              v-model="form.material.texture.foliation"
              type="text"
              class="form-input"
              placeholder="e.g., None"
            />
          </div>
          <div>
            <label for="fault" class="form-label text-xs">Fault</label>
            <input
              id="fault"
              v-model="form.material.texture.fault"
              type="text"
              class="form-input"
              placeholder="e.g., None"
            />
          </div>
        </div>
      </div>
    </CollapsibleSection>

    <!-- Parameters -->
    <CollapsibleSection title="Parameters" class="mt-4">
      <p class="text-sm text-strabo-text-secondary mb-4">
        Pre-experimental sample parameters (weight, porosity, density, etc.).
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
              <label class="form-label text-xs">Variable</label>
              <select v-model="param.control" class="form-select text-sm">
                <option value="">Select...</option>
                <option v-for="t in SAMPLE_PARAMETER_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div>
              <label class="form-label text-xs">Value</label>
              <input v-model="param.value" type="text" class="form-input text-sm" />
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
              <label class="form-label text-xs">Note (Measurement and Treatment)</label>
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
        Upload sample images, thin section photos, or other documentation.
      </p>
      <div class="text-center py-8 border-2 border-dashed border-strabo-border rounded">
        <p class="text-strabo-text-secondary">Document upload coming soon</p>
      </div>
    </CollapsibleSection>

    <!-- Actions -->
    <div class="flex justify-center gap-3 mt-6">
      <button type="button" class="btn-secondary" @click="$emit('cancel')">
        Cancel
      </button>
      <button type="submit" class="btn-primary" :disabled="!isValid">
        Save Sample
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import CollapsibleSection from '@/components/CollapsibleSection.vue'
import {
  MATERIAL_TYPES,
  LITHOLOGY_TYPES,
  MINERAL_TYPES,
  SAMPLE_PARAMETER_TYPES,
  FRACTION_UNITS,
  UNIT_TYPES,
  UNIT_PREFIXES
} from '@/schemas/laps-enums'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const createEmptyForm = () => ({
  name: '',
  id: '',
  description: '',
  material: {
    material: '',
    lithology: '',
    composition: [],
    provenance: {
      location: '',
      latitude: '',
      longitude: '',
      note: ''
    },
    texture: {
      bedding: '',
      lineation: '',
      foliation: '',
      fault: ''
    }
  },
  parameters: [],
  documents: []
})

const form = ref(createEmptyForm())

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      name: data.name || '',
      id: data.id || '',
      description: data.description || '',
      material: {
        material: data.material?.material || '',
        lithology: data.material?.lithology || '',
        composition: data.material?.composition?.map(c => ({ ...c })) || [],
        provenance: {
          location: data.material?.provenance?.location || '',
          latitude: data.material?.provenance?.latitude || '',
          longitude: data.material?.provenance?.longitude || '',
          note: data.material?.provenance?.note || ''
        },
        texture: {
          bedding: data.material?.texture?.bedding || '',
          lineation: data.material?.texture?.lineation || '',
          foliation: data.material?.texture?.foliation || '',
          fault: data.material?.texture?.fault || ''
        }
      },
      parameters: data.parameters?.map(p => ({ ...p })) || [],
      documents: data.documents?.map(d => ({ ...d })) || []
    }
  }
}, { immediate: true, deep: true })

const isValid = computed(() => {
  return form.value.name.trim().length > 0
})

function addPhase() {
  form.value.material.composition.push({
    mineral: '',
    fraction: '',
    unit: '%',
    grainsize: ''
  })
}

function removePhase(idx) {
  form.value.material.composition.splice(idx, 1)
}

function addParameter() {
  form.value.parameters.push({
    control: '',
    value: '',
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

<style scoped>
.sample-form {
  max-width: 900px;
  margin: 0 auto;
}

.form-label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.25rem;
  color: var(--strabo-text-primary);
  font-size: 0.875rem;
}

.form-input,
.form-select,
.form-textarea {
  width: 100%;
  padding: 0.5rem 0.75rem;
  background-color: var(--strabo-bg-primary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.375rem;
  color: var(--strabo-text-primary);
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
  outline: none;
  border-color: var(--strabo-accent);
  box-shadow: 0 0 0 2px rgba(244, 81, 30, 0.2);
}

.form-textarea {
  resize: vertical;
  min-height: 80px;
}
</style>
