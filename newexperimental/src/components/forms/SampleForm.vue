<template>
  <form @submit.prevent="handleSubmit" class="sample-form">
    <!-- Sample Info Section -->
    <fieldset class="form-section">
      <legend>SAMPLE INFO</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Sample Name *</label>
          <InputText v-model="form.name" :invalid="!form.name" />
        </div>
        <div class="field">
          <label class="text-sm">IGSN</label>
          <InputText v-model="form.igsn" />
        </div>
        <div class="field">
          <label class="text-sm">Sample ID</label>
          <InputText v-model="form.id" />
        </div>
        <div class="field">
          <label class="text-sm">Description</label>
          <InputText v-model="form.description" />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Parent Sample Name</label>
          <InputText v-model="form.parent.name" />
        </div>
        <div class="field">
          <label class="text-sm">Parent IGSN</label>
          <InputText v-model="form.parent.igsn" />
        </div>
        <div class="field">
          <label class="text-sm">Parent Sample ID</label>
          <InputText v-model="form.parent.id" />
        </div>
        <div class="field">
          <label class="text-sm">Parent Description</label>
          <InputText v-model="form.parent.description" />
        </div>
      </div>
    </fieldset>

    <!-- Material Section -->
    <fieldset class="form-section">
      <legend>MATERIAL</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Material Type *</label>
          <Select
            v-model="form.material.material.type"
            :options="MATERIAL_TYPES"
            placeholder="Select..."
            showClear
            @update:modelValue="handleMaterialTypeChange"
          />
        </div>
        <!-- Dynamic Material Name field - text input or dropdown based on type -->
        <div class="field">
          <label class="text-sm">{{ materialNameLabel }} *</label>
          <!-- Text input for Glass, Ice, Ceramic, Plastic, Metal, or no type selected -->
          <InputText
            v-if="useTextInputForName"
            v-model="form.material.material.name"
          />
          <!-- Dropdown for other material types -->
          <Select
            v-else
            v-model="form.material.material.name"
            :options="materialNameOptions"
            placeholder="Select..."
            showClear
            filter
            filterPlaceholder="Search..."
          />
        </div>
        <!-- Other name field (shown when dropdown value is "Other") -->
        <div class="field" v-if="!useTextInputForName && isOther(form.material.material.name)">
          <label class="text-sm">Other Name</label>
          <InputText v-model="form.material.material.other_name" placeholder="Enter other name..." />
        </div>
        <div class="field">
          <label class="text-sm">State</label>
          <Select v-model="form.material.material.state" :options="MATERIAL_STATES" placeholder="Select..." showClear />
        </div>
        <div class="field">
          <label class="text-sm">Note</label>
          <InputText v-model="form.material.material.note" />
        </div>
      </div>
    </fieldset>

    <!-- Mineralogy Section -->
    <fieldset class="form-section">
      <legend>MINERALOGY</legend>
      <ListDetailEditor
        title=""
        add-label="Add Phase"
        :items="form.material.composition"
        :default-item="defaultPhase"
        :label-function="(item, idx) => item.mineral || `Phase ${idx + 1}`"
        @update:items="form.material.composition = $event"
      >
        <template #detail="{ item, update }">
          <div class="flex flex-col gap-3">
            <div class="flex gap-3">
              <div class="field flex-1">
                <label class="text-sm">Mineral *</label>
                <Select
                  :modelValue="item.mineral"
                  @update:modelValue="update('mineral', $event)"
                  :options="MINERAL_TYPES"
                  placeholder="Select..."
                  showClear
                />
              </div>
              <div class="field flex-1" v-if="isOther(item.mineral)">
                <label class="text-sm">Other Mineral</label>
                <InputText
                  :modelValue="item.other_mineral"
                  @update:modelValue="update('other_mineral', $event)"
                  placeholder="Enter other mineral..."
                />
              </div>
              <div class="field w-24">
                <label class="text-sm">Fraction</label>
                <InputText
                  :modelValue="item.fraction"
                  @update:modelValue="update('fraction', $event)"
                />
              </div>
              <div class="field w-32">
                <label class="text-sm">Grain Size [μm]</label>
                <InputText
                  :modelValue="item.grainsize"
                  @update:modelValue="update('grainsize', $event)"
                />
              </div>
              <div class="field w-24">
                <label class="text-sm">Unit</label>
                <Select
                  :modelValue="item.unit"
                  @update:modelValue="update('unit', $event)"
                  :options="FRACTION_UNITS"
                />
              </div>
            </div>
          </div>
        </template>
      </ListDetailEditor>
    </fieldset>

    <!-- Provenance Section -->
    <fieldset class="form-section">
      <legend>PROVENANCE</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Formation Name</label>
          <InputText v-model="form.material.provenance.formation" />
        </div>
        <div class="field">
          <label class="text-sm">Member Name</label>
          <InputText v-model="form.material.provenance.member" />
        </div>
        <div class="field">
          <label class="text-sm">Sub Member Name</label>
          <InputText v-model="form.material.provenance.submember" />
        </div>
        <div class="field">
          <label class="text-sm">Source</label>
          <Select v-model="form.material.provenance.source" :options="SOURCE_TYPES" placeholder="Select..." showClear />
        </div>
        <div class="field" v-if="isOther(form.material.provenance.source)">
          <label class="text-sm">Other Source</label>
          <InputText v-model="form.material.provenance.other_source" placeholder="Enter other source..." />
        </div>
      </div>
    </fieldset>

    <!-- Location Section -->
    <fieldset class="form-section">
      <legend>LOCATION</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Street + Number</label>
          <InputText v-model="form.material.provenance.location.street" />
        </div>
        <div class="field">
          <label class="text-sm">Building - Apt</label>
          <InputText v-model="form.material.provenance.location.building" />
        </div>
        <div class="field">
          <label class="text-sm">Postal Code</label>
          <InputText v-model="form.material.provenance.location.postcode" />
        </div>
        <div class="field">
          <label class="text-sm">City</label>
          <InputText v-model="form.material.provenance.location.city" />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">State</label>
          <InputText v-model="form.material.provenance.location.state" />
        </div>
        <div class="field">
          <label class="text-sm">Country</label>
          <InputText v-model="form.material.provenance.location.country" />
        </div>
        <div class="field">
          <label class="text-sm">Latitude</label>
          <InputText v-model="form.material.provenance.location.latitude" />
        </div>
        <div class="field">
          <label class="text-sm">Longitude</label>
          <InputText v-model="form.material.provenance.location.longitude" />
        </div>
      </div>
    </fieldset>

    <!-- Texture Section -->
    <fieldset class="form-section">
      <legend>TEXTURE</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Bedding</label>
          <InputText v-model="form.material.texture.bedding" />
        </div>
        <div class="field">
          <label class="text-sm">Lineation</label>
          <InputText v-model="form.material.texture.lineation" />
        </div>
        <div class="field">
          <label class="text-sm">Foliation</label>
          <InputText v-model="form.material.texture.foliation" />
        </div>
        <div class="field">
          <label class="text-sm">Fault</label>
          <InputText v-model="form.material.texture.fault" />
        </div>
      </div>
    </fieldset>

    <!-- Parameters Section -->
    <fieldset class="form-section">
      <legend>PARAMETERS</legend>
      <ListDetailEditor
        title=""
        add-label="Add Parameter"
        :items="form.parameters"
        :default-item="defaultParameter"
        :label-function="(item, idx) => item.control || `Param ${idx + 1}`"
        @update:items="form.parameters = $event"
      >
        <template #detail="{ item, update }">
          <div class="flex flex-col gap-3">
            <div class="flex gap-3">
              <div class="field flex-1">
                <label class="text-sm">Variable *</label>
                <Select
                  :modelValue="item.control"
                  @update:modelValue="update('control', $event)"
                  :options="SAMPLE_PARAMETER_TYPES"
                  placeholder="Select..."
                  showClear
                />
              </div>
              <div class="field flex-1" v-if="isOther(item.control)">
                <label class="text-sm">Other Variable</label>
                <InputText
                  :modelValue="item.other_control"
                  @update:modelValue="update('other_control', $event)"
                  placeholder="Enter other variable..."
                />
              </div>
              <div class="field w-28">
                <label class="text-sm">Value</label>
                <InputText
                  :modelValue="item.value"
                  @update:modelValue="update('value', $event)"
                />
              </div>
              <div class="field w-36">
                <label class="text-sm">Unit</label>
                <Select
                  :modelValue="item.unit"
                  @update:modelValue="update('unit', $event)"
                  :options="UNIT_TYPES"
                  showClear
                />
              </div>
              <div class="field w-24">
                <label class="text-sm">Prefix</label>
                <Select
                  :modelValue="item.prefix"
                  @update:modelValue="update('prefix', $event)"
                  :options="prefixOptions"
                />
              </div>
            </div>
            <div class="field">
              <label class="text-sm">Note (Measurement and Treatment)</label>
              <InputText
                :modelValue="item.note"
                @update:modelValue="update('note', $event)"
              />
            </div>
          </div>
        </template>
      </ListDetailEditor>
    </fieldset>

    <!-- Documents Section -->
    <fieldset class="form-section">
      <legend>DOCUMENTS</legend>
      <p class="text-sm text-surface-400 mb-4">
        Document upload coming soon.
      </p>
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
        label="Save Sample"
        :disabled="!isValid"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import {
  MATERIAL_TYPES,
  MINERAL_TYPES,
  SAMPLE_PARAMETER_TYPES,
  FRACTION_UNITS,
  UNIT_TYPES,
  UNIT_PREFIXES,
  MATERIAL_STATES,
  TEXT_INPUT_MATERIAL_TYPES,
  MATERIAL_NAME_LABELS,
  MATERIAL_NAME_OPTIONS,
  SOIL_TYPES,
  IGNEOUS_ROCK_TYPES,
  SEDIMENTARY_ROCK_TYPES,
  METAMORPHIC_ROCK_TYPES,
  EPOS_LITHOLOGY_TYPES,
  LAB_STANDARDS,
  COMMODITY_TYPES
} from '@/schemas/laps-enums'

const SOURCE_TYPES = ['Quarry', 'Mine', 'Outcrop', 'Core', 'Laboratory', 'Commercial', 'Other']

// Check if material type uses text input (vs dropdown)
const usesTextInput = (materialType) => {
  return !materialType || TEXT_INPUT_MATERIAL_TYPES.includes(materialType)
}

// Get the label for material name field based on material type
const getMaterialNameLabel = (materialType) => {
  return MATERIAL_NAME_LABELS[materialType] || 'Name'
}

// Get the dropdown options for material name based on material type
const getMaterialNameOptions = (materialType) => {
  if (materialType === 'Mineral') {
    return MINERAL_TYPES
  }
  return MATERIAL_NAME_OPTIONS[materialType] || []
}

// Helper to check if a value is "Other" (case-insensitive)
const isOther = (value) => value && value.toLowerCase() === 'other'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const prefixOptions = computed(() => ['-', ...UNIT_PREFIXES])

// Computed: should we use text input for material name?
const useTextInputForName = computed(() => {
  return usesTextInput(form.value.material.material.type)
})

// Computed: label for material name field
const materialNameLabel = computed(() => {
  return getMaterialNameLabel(form.value.material.material.type)
})

// Computed: dropdown options for material name
const materialNameOptions = computed(() => {
  return getMaterialNameOptions(form.value.material.material.type)
})

// Handler for material type change - clears the name when type changes
const handleMaterialTypeChange = (newType) => {
  // Clear the name field when material type changes
  form.value.material.material.name = ''
  form.value.material.material.other_name = ''
}

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
      other_name: '',
      state: '',
      note: ''
    },
    composition: [],
    provenance: {
      formation: '',
      member: '',
      submember: '',
      source: '',
      other_source: '',
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
          other_name: data.material?.material?.other_name || '',
          state: data.material?.material?.state || '',
          note: data.material?.material?.note || ''
        },
        composition: data.material?.composition?.map(c => ({ ...c })) || [],
        provenance: {
          formation: data.material?.provenance?.formation || '',
          member: data.material?.provenance?.member || '',
          submember: data.material?.provenance?.submember || '',
          source: data.material?.provenance?.source || '',
          other_source: data.material?.provenance?.other_source || '',
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

// Default item factory functions for ListDetailEditor
const defaultPhase = () => ({
  mineral: '',
  other_mineral: '',
  fraction: '',
  unit: 'Vol%',
  grainsize: ''
})

const defaultParameter = () => ({
  control: '',
  other_control: '',
  value: '',
  unit: '',
  prefix: '-',
  note: ''
})

function handleSubmit() {
  if (!isValid.value) return
  emit('submit', form.value)
}
</script>

<style scoped>
.sample-form {
  max-width: 1100px;
  margin: 0 auto;
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
</style>
