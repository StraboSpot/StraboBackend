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

        <div>
          <label for="igsn" class="form-label">IGSN</label>
          <input
            id="igsn"
            v-model="form.igsn"
            type="text"
            class="form-input"
            placeholder="International Geo Sample Number"
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

      <!-- Parent Sample -->
      <div class="mt-4 pt-4 border-t border-strabo-border">
        <h4 class="text-sm font-semibold text-strabo-text-primary mb-3">Parent Sample (Optional)</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label for="parentName" class="form-label text-xs">Parent Name</label>
            <input
              id="parentName"
              v-model="form.parent.name"
              type="text"
              class="form-input"
              placeholder="Parent sample name"
            />
          </div>
          <div>
            <label for="parentId" class="form-label text-xs">Parent ID</label>
            <input
              id="parentId"
              v-model="form.parent.id"
              type="text"
              class="form-input"
              placeholder="Parent sample ID"
            />
          </div>
          <div>
            <label for="parentIgsn" class="form-label text-xs">Parent IGSN</label>
            <input
              id="parentIgsn"
              v-model="form.parent.igsn"
              type="text"
              class="form-input"
              placeholder="Parent IGSN"
            />
          </div>
          <div>
            <label for="parentDescription" class="form-label text-xs">Parent Description</label>
            <input
              id="parentDescription"
              v-model="form.parent.description"
              type="text"
              class="form-input"
              placeholder="Parent description"
            />
          </div>
        </div>
      </div>
    </CollapsibleSection>

    <!-- Material -->
    <CollapsibleSection title="Material" class="mt-4">
      <!-- Material Type (nested object: type, name, state, note) -->
      <h4 class="text-sm font-semibold text-strabo-text-primary mb-3">Material Type</h4>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
          <label for="materialType" class="form-label text-xs">Type</label>
          <select id="materialType" v-model="form.material.material.type" class="form-select">
            <option value="">Select type...</option>
            <option v-for="t in MATERIAL_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label for="materialName" class="form-label text-xs">Name</label>
          <input
            id="materialName"
            v-model="form.material.material.name"
            type="text"
            class="form-input"
            placeholder="e.g., Carrara Marble"
          />
        </div>
        <div>
          <label for="materialState" class="form-label text-xs">State</label>
          <input
            id="materialState"
            v-model="form.material.material.state"
            type="text"
            class="form-input"
            placeholder="e.g., Homogeneous"
          />
        </div>
        <div>
          <label for="materialNote" class="form-label text-xs">Note</label>
          <input
            id="materialNote"
            v-model="form.material.material.note"
            type="text"
            class="form-input"
            placeholder="Additional notes"
          />
        </div>
      </div>

      <!-- Mineralogy / Composition -->
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
                <input v-model="phase.fraction" type="text" class="form-input text-sm" placeholder="e.g., 0.99" />
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
                <input v-model="phase.grainsize" type="text" class="form-input text-sm" placeholder="e.g., 150" />
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
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label for="provFormation" class="form-label text-xs">Formation</label>
            <input
              id="provFormation"
              v-model="form.material.provenance.formation"
              type="text"
              class="form-input"
              placeholder="e.g., Carrara (Italy)"
            />
          </div>
          <div>
            <label for="provMember" class="form-label text-xs">Member</label>
            <input
              id="provMember"
              v-model="form.material.provenance.member"
              type="text"
              class="form-input"
              placeholder="e.g., Hettangian"
            />
          </div>
          <div>
            <label for="provSubmember" class="form-label text-xs">Submember</label>
            <input
              id="provSubmember"
              v-model="form.material.provenance.submember"
              type="text"
              class="form-input"
              placeholder="Submember name"
            />
          </div>
          <div>
            <label for="provSource" class="form-label text-xs">Source</label>
            <input
              id="provSource"
              v-model="form.material.provenance.source"
              type="text"
              class="form-input"
              placeholder="e.g., Quarry"
            />
          </div>
        </div>

        <!-- Location (nested within provenance) -->
        <div class="mt-4 pt-4 border-t border-strabo-border">
          <h5 class="text-xs font-semibold text-strabo-text-secondary mb-3 uppercase">Location</h5>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label class="form-label text-xs">Street</label>
              <input
                v-model="form.material.provenance.location.street"
                type="text"
                class="form-input"
                placeholder="Street name"
              />
            </div>
            <div>
              <label class="form-label text-xs">Building</label>
              <input
                v-model="form.material.provenance.location.building"
                type="text"
                class="form-input"
                placeholder="Building"
              />
            </div>
            <div>
              <label class="form-label text-xs">City</label>
              <input
                v-model="form.material.provenance.location.city"
                type="text"
                class="form-input"
                placeholder="City"
              />
            </div>
            <div>
              <label class="form-label text-xs">State/Region</label>
              <input
                v-model="form.material.provenance.location.state"
                type="text"
                class="form-input"
                placeholder="State/Region"
              />
            </div>
            <div>
              <label class="form-label text-xs">Postcode</label>
              <input
                v-model="form.material.provenance.location.postcode"
                type="text"
                class="form-input"
                placeholder="Postal code"
              />
            </div>
            <div>
              <label class="form-label text-xs">Country</label>
              <input
                v-model="form.material.provenance.location.country"
                type="text"
                class="form-input"
                placeholder="Country"
              />
            </div>
            <div>
              <label class="form-label text-xs">Latitude</label>
              <input
                v-model="form.material.provenance.location.latitude"
                type="text"
                class="form-input"
                placeholder="e.g., 44.0793"
              />
            </div>
            <div>
              <label class="form-label text-xs">Longitude</label>
              <input
                v-model="form.material.provenance.location.longitude"
                type="text"
                class="form-input"
                placeholder="e.g., 10.0979"
              />
            </div>
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
              placeholder="e.g., no bedding"
            />
          </div>
          <div>
            <label for="lineation" class="form-label text-xs">Lineation</label>
            <input
              id="lineation"
              v-model="form.material.texture.lineation"
              type="text"
              class="form-input"
              placeholder="e.g., no apparent lineation"
            />
          </div>
          <div>
            <label for="foliation" class="form-label text-xs">Foliation</label>
            <input
              id="foliation"
              v-model="form.material.texture.foliation"
              type="text"
              class="form-input"
              placeholder="e.g., no foliation"
            />
          </div>
          <div>
            <label for="fault" class="form-label text-xs">Fault</label>
            <input
              id="fault"
              v-model="form.material.texture.fault"
              type="text"
              class="form-input"
              placeholder="e.g., no faults"
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
                <option value="-">-</option>
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
  igsn: '',
  description: '',
  parent: {
    name: '',
    id: '',
    igsn: '',
    description: ''
  },
  material: {
    material: {
      type: '',
      name: '',
      state: '',
      note: ''
    },
    composition: [],
    provenance: {
      formation: '',
      member: '',
      submember: '',
      source: '',
      location: {
        street: '',
        building: '',
        postcode: '',
        city: '',
        state: '',
        country: '',
        latitude: '',
        longitude: ''
      }
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
      igsn: data.igsn || '',
      description: data.description || '',
      parent: {
        name: data.parent?.name || '',
        id: data.parent?.id || '',
        igsn: data.parent?.igsn || '',
        description: data.parent?.description || ''
      },
      material: {
        material: {
          type: data.material?.material?.type || '',
          name: data.material?.material?.name || '',
          state: data.material?.material?.state || '',
          note: data.material?.material?.note || ''
        },
        composition: data.material?.composition?.map(c => ({ ...c })) || [],
        provenance: {
          formation: data.material?.provenance?.formation || '',
          member: data.material?.provenance?.member || '',
          submember: data.material?.provenance?.submember || '',
          source: data.material?.provenance?.source || '',
          location: {
            street: data.material?.provenance?.location?.street || '',
            building: data.material?.provenance?.location?.building || '',
            postcode: data.material?.provenance?.location?.postcode || '',
            city: data.material?.provenance?.location?.city || '',
            state: data.material?.provenance?.location?.state || '',
            country: data.material?.provenance?.location?.country || '',
            latitude: data.material?.provenance?.location?.latitude || '',
            longitude: data.material?.provenance?.location?.longitude || ''
          }
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
    unit: 'Vol%',
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
    prefix: '-',
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
