<template>
  <form @submit.prevent="handleSubmit" class="experiment-form">
    <!-- Experiment Info Section -->
    <fieldset class="form-section">
      <legend>EXPERIMENT INFO</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field md:col-span-2">
          <label class="text-sm">Title *</label>
          <InputText v-model="form.title" :invalid="!form.title" />
        </div>
        <div class="field">
          <label class="text-sm">Experiment ID</label>
          <InputText v-model="form.id" />
        </div>
        <div class="field">
          <label class="text-sm">IEDA ID</label>
          <InputText v-model="form.ieda" />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Start Date/Time</label>
          <DatePicker
            v-model="form.start_date"
            showTime
            hourFormat="12"
            dateFormat="mm/dd/yy"
            placeholder="Select date/time..."
            showIcon
            iconDisplay="input"
          />
        </div>
        <div class="field">
          <label class="text-sm">End Date/Time</label>
          <DatePicker
            v-model="form.end_date"
            showTime
            hourFormat="12"
            dateFormat="mm/dd/yy"
            placeholder="Select date/time..."
            showIcon
            iconDisplay="input"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Experimental Description</label>
          <Textarea
            v-model="form.description"
            rows="3"
            autoResize
          />
        </div>
      </div>
    </fieldset>

    <!-- Test Features Section -->
    <fieldset class="form-section">
      <legend>TEST FEATURES</legend>
      <p class="text-sm text-surface-400 mb-3">Select experimental test capabilities:</p>
      <FeaturePills
        :features="APPARATUS_FEATURES"
        v-model="form.features"
      />
    </fieldset>

    <!-- Author Section -->
    <fieldset class="form-section">
      <legend>AUTHOR</legend>
      <p class="text-sm text-surface-400 mb-3">Dataset Author Information:</p>
      <ContactFields v-model="form.author" />
    </fieldset>

    <!-- Geometry Section -->
    <fieldset class="form-section">
      <legend>GEOMETRY</legend>
      <p class="text-sm text-surface-400 mb-3">Sample and/or Assemblage Geometry:</p>
      <ListDetailEditor
        title=""
        add-label="Add Component"
        :items="form.geometry"
        :default-item="defaultGeometry"
        :label-function="(item, idx) => `${item.type || 'Component'} #${item.order || idx + 1}`"
        @update:items="form.geometry = $event"
      >
        <template #detail="{ item, update }">
          <div class="flex flex-col gap-3">
            <!-- Row 1: Order, Type, Geometry, Material -->
            <div class="flex gap-3">
              <div class="field w-16">
                <label class="text-sm">#</label>
                <InputText
                  :modelValue="item.order"
                  @update:modelValue="update('order', $event)"
                />
              </div>
              <div class="field flex-1">
                <label class="text-sm">Type</label>
                <Select
                  :modelValue="item.type"
                  @update:modelValue="update('type', $event)"
                  :options="GEOMETRY_COMPONENT_TYPES"
                  placeholder="Select..."
                  showClear
                />
              </div>
              <div class="field flex-1">
                <label class="text-sm">Geometry</label>
                <Select
                  :modelValue="item.geometry"
                  @update:modelValue="update('geometry', $event)"
                  :options="GEOMETRY_SHAPES"
                  placeholder="Select..."
                  showClear
                />
              </div>
              <div class="field flex-1">
                <label class="text-sm">Material</label>
                <Select
                  :modelValue="item.material"
                  @update:modelValue="update('material', $event)"
                  :options="GEOMETRY_MATERIALS"
                  placeholder="Select..."
                  showClear
                />
              </div>
            </div>

            <!-- Dimensions sub-table -->
            <div class="dimensions-section mt-2">
              <div class="flex items-center gap-2 mb-2">
                <span class="text-sm font-medium">Dimensions</span>
                <Button
                  size="small"
                  label="Add Dimension"
                  @click="addDimension(item, update)"
                />
              </div>

              <div v-if="item.dimensions && item.dimensions.length > 0" class="dimensions-table">
                <div
                  v-for="(dim, dimIdx) in item.dimensions"
                  :key="dimIdx"
                  class="dimension-row flex gap-2 items-end mb-2"
                >
                  <div class="field dim-variable">
                    <label class="text-xs" v-if="dimIdx === 0">Variable</label>
                    <Select
                      :modelValue="dim.variable"
                      @update:modelValue="updateDimension(item, dimIdx, 'variable', $event, update)"
                      :options="DIMENSION_VARIABLES"
                      placeholder="Select..."
                    />
                  </div>
                  <div class="field dim-value">
                    <label class="text-xs" v-if="dimIdx === 0">Value</label>
                    <InputText
                      :modelValue="dim.value"
                      @update:modelValue="updateDimension(item, dimIdx, 'value', $event, update)"
                    />
                  </div>
                  <div class="field dim-unit">
                    <label class="text-xs" v-if="dimIdx === 0">Unit</label>
                    <Select
                      :modelValue="dim.unit"
                      @update:modelValue="updateDimension(item, dimIdx, 'unit', $event, update)"
                      :options="UNIT_TYPES"
                    />
                  </div>
                  <div class="field dim-prefix">
                    <label class="text-xs" v-if="dimIdx === 0">Prefix</label>
                    <Select
                      :modelValue="dim.prefix"
                      @update:modelValue="updateDimension(item, dimIdx, 'prefix', $event, update)"
                      :options="prefixOptions"
                    />
                  </div>
                  <div class="field flex-1">
                    <label class="text-xs" v-if="dimIdx === 0">Note</label>
                    <InputText
                      :modelValue="dim.note"
                      @update:modelValue="updateDimension(item, dimIdx, 'note', $event, update)"
                    />
                  </div>
                  <Button
                    icon="pi pi-times"
                    severity="secondary"
                    text
                    size="small"
                    @click="removeDimension(item, dimIdx, update)"
                    class="mb-1"
                  />
                </div>
              </div>
              <p v-else class="text-xs text-surface-500">No dimensions added.</p>
            </div>
          </div>
        </template>
      </ListDetailEditor>
    </fieldset>

    <!-- Documents Section -->
    <fieldset class="form-section">
      <legend>DOCUMENTS</legend>
      <DocumentsEditor
        v-model="form.documents"
        add-label="Add Document"
      />
    </fieldset>

    <!-- Actions -->
    <div class="flex justify-center gap-3 mt-6">
      <Button
        type="button"
        severity="secondary"
        outlined
        label="Cancel"
        @click="$emit('cancel')"
      />
      <Button
        type="submit"
        label="Save Experimental Setup"
        :disabled="!isValid"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import FeaturePills from '@/components/FeaturePills.vue'
import ContactFields from '@/components/ContactFields.vue'
import DocumentsEditor from '@/components/DocumentsEditor.vue'
import {
  APPARATUS_FEATURES,
  GEOMETRY_COMPONENT_TYPES,
  GEOMETRY_SHAPES,
  GEOMETRY_MATERIALS,
  DIMENSION_VARIABLES,
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

const toast = useToast()

const prefixOptions = computed(() => ['-', ...UNIT_PREFIXES])

const createEmptyForm = () => ({
  title: '',
  id: '',
  ieda: '',
  start_date: null,
  end_date: null,
  description: '',
  features: [],
  author: {
    firstname: '',
    lastname: '',
    affiliation: '',
    email: '',
    phone: '',
    website: '',
    id: ''
  },
  geometry: [],
  documents: []
})

const form = ref(createEmptyForm())

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      title: data.title || '',
      id: data.id || '',
      ieda: data.ieda || '',
      start_date: data.start_date ? new Date(data.start_date) : null,
      end_date: data.end_date ? new Date(data.end_date) : null,
      description: data.description || '',
      features: data.features ? [...data.features] : [],
      author: {
        firstname: data.author?.firstname || '',
        lastname: data.author?.lastname || '',
        affiliation: data.author?.affiliation || '',
        email: data.author?.email || '',
        phone: data.author?.phone || '',
        website: data.author?.website || '',
        id: data.author?.id || ''
      },
      geometry: data.geometry?.map(g => ({
        order: g.order || '',
        type: g.type || '',
        geometry: g.geometry || '',
        material: g.material || '',
        dimensions: g.dimensions?.map(d => ({ ...d })) || []
      })) || [],
      documents: data.documents?.map(d => ({ ...d })) || []
    }
  }
}, { immediate: true, deep: true })

const isValid = computed(() => {
  return form.value.title.trim().length > 0
})

// Default factory for geometry items
const defaultGeometry = () => ({
  order: '',
  type: '',
  geometry: '',
  material: '',
  dimensions: []
})

// Helper functions for dimensions
function addDimension(item, update) {
  const newDimensions = [...(item.dimensions || []), {
    variable: '',
    value: '',
    unit: '',
    prefix: '-',
    note: ''
  }]
  update('dimensions', newDimensions)
}

function updateDimension(item, dimIdx, field, value, update) {
  const newDimensions = [...item.dimensions]
  newDimensions[dimIdx] = { ...newDimensions[dimIdx], [field]: value }
  update('dimensions', newDimensions)
}

function removeDimension(item, dimIdx, update) {
  const newDimensions = item.dimensions.filter((_, i) => i !== dimIdx)
  update('dimensions', newDimensions)
}

function handleSubmit() {
  // Validate
  const errors = []
  if (!form.value.title || form.value.title.trim() === '') {
    errors.push('Title cannot be blank.')
  }

  if (errors.length > 0) {
    toast.add({
      severity: 'error',
      summary: 'Validation Error',
      detail: errors.join('\n'),
      life: 5000
    })
    return
  }

  // Convert dates to ISO strings for storage
  const formData = {
    ...form.value,
    start_date: form.value.start_date ? form.value.start_date.toISOString() : null,
    end_date: form.value.end_date ? form.value.end_date.toISOString() : null
  }

  emit('submit', formData)
}
</script>

<style scoped>
.experiment-form {
  width: 100%;
  
}

.form-section {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 1rem 1.25rem;
  margin-bottom: 1rem;
}

.form-section legend {
  font-size: 0.875rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-300);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* Field wrapper with tight label-to-input spacing */
.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}

.dimensions-section {
  background: var(--p-surface-800);
  border-radius: 4px;
  padding: 0.75rem;
}

.dimension-row {
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--p-surface-700);
}

.dimension-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

/* Dimension column widths */
.dim-variable {
  width: 140px;
  flex-shrink: 0;
}

.dim-value {
  width: 90px;
  flex-shrink: 0;
}

.dim-unit {
  width: 100px;
  flex-shrink: 0;
}

.dim-prefix {
  width: 90px;
  flex-shrink: 0;
}
</style>
