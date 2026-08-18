<template>
  <form @submit.prevent="handleSubmit" class="sample-form">
    <!-- Sample Info Section -->
    <fieldset class="form-section">
      <legend>SAMPLE INFO</legend>

      <!-- StraboSamples link (Exp_StraboSamples_Linking.md) -->
      <div v-if="!linkedId" class="mb-4">
        <Button
          type="button"
          class="w-full link-samples-btn"
          outlined
          icon="pi pi-link"
          label="Link Sample From StraboSamples"
          @click="showLinkDialog = true"
        />
      </div>
      <div v-else class="linked-chip mb-4">
        <i class="pi pi-link" />
        <span>
          Linked to StraboSamples:
          <a v-if="linkedUrl" :href="linkedUrl" target="_blank" rel="noopener" class="linked-chip-link">
            <strong>{{ linkedLabel }}</strong>
            <i class="pi pi-external-link text-xs" />
          </a>
          <strong v-else>{{ linkedLabel }}</strong>
        </span>
        <span v-if="identityLocked" class="text-xs italic text-surface-400">
          identity managed by {{ managedByLabel }}
        </span>
        <span class="flex-1"></span>
        <Button type="button" label="Change" size="small" text @click="showLinkDialog = true" />
        <Button type="button" label="Unlink" size="small" text severity="danger" @click="handleUnlink" />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Sample Name *</label>
          <InputText v-model="form.name" :invalid="!form.name" :disabled="identityLocked" />
        </div>
        <div class="field">
          <label class="text-sm">IGSN</label>
          <InputText v-model="form.igsn" :disabled="identityLocked" />
        </div>
        <div class="field">
          <label class="text-sm">Sample ID *</label>
          <InputText v-model="form.id" :invalid="!form.id" :disabled="identityLocked" />
        </div>
        <div class="field">
          <label class="text-sm">Description</label>
          <InputText v-model="form.description" :disabled="identityLocked" />
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
            filter
            resetFilterOnHide
            :invalid="!form.material.material.type"
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
            :invalid="!form.material.material.name"
          />
          <!-- Dropdown for other material types -->
          <Select
            v-else
            v-model="form.material.material.name"
            :options="materialNameOptions"
            placeholder="Select..."
            showClear
            filter
            resetFilterOnHide
            filterPlaceholder="Search..."
            :invalid="!form.material.material.name"
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
                  filter
                  resetFilterOnHide
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
          <Select v-model="form.material.provenance.source" :options="PROVENANCE_SOURCES" placeholder="Select..." showClear />
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
          <InputText v-model="form.material.provenance.location.latitude" :disabled="identityLocked" />
        </div>
        <div class="field">
          <label class="text-sm">Longitude</label>
          <InputText v-model="form.material.provenance.location.longitude" :disabled="identityLocked" />
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
                  @update:modelValue="(val) => { const u = {}; u.control = val; if (item.unit && !getUnitsForVariable(val).includes(item.unit)) u.unit = ''; update(u) }"
                  :options="SAMPLE_PARAMETER_TYPES"
                  placeholder="Select..."
                  showClear
                  filter
                  resetFilterOnHide
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
                  :options="getUnitsForVariable(item.control)"
                  showClear
                  :filter="getUnitsForVariable(item.control).length > 8"
                  resetFilterOnHide
                />
              </div>
              <div class="field w-24">
                <label class="text-sm">Prefix</label>
                <Select
                  :modelValue="item.prefix"
                  @update:modelValue="update('prefix', $event)"
                  :options="prefixOptions"
                  filter
                  resetFilterOnHide
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
        label="Discard Changes"
        @click="$emit('cancel')"
      />
      <Button
        type="submit"
        label="Save Sample"
        :disabled="!isValid"
      />
    </div>

    <!-- StraboSamples picker -->
    <LinkSampleDialog
      v-if="showLinkDialog"
      :current-id="linkedId"
      @close="showLinkDialog = false"
      @select="handleSelectSample"
    />
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useFormDirty } from '@/composables/useFormDirty'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import DocumentsEditor from '@/components/DocumentsEditor.vue'
import LinkSampleDialog from '@/components/LinkSampleDialog.vue'
import { sampleLinkService } from '@/services/api'
import {
  MATERIAL_TYPES,
  MINERAL_TYPES,
  SAMPLE_PARAMETER_TYPES,
  FRACTION_UNITS,
  UNIT_TYPES,
  UNIT_PREFIXES,
  getUnitsForVariable,
  MATERIAL_STATES,
  TEXT_INPUT_MATERIAL_TYPES,
  MATERIAL_NAME_LABELS,
  MATERIAL_NAME_OPTIONS,
  PROVENANCE_SOURCES
} from '@/schemas/laps-enums'

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

const toast = useToast()

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
  // StraboSamples spine id. Semantics (Exp_StraboSamples_Linking.md D3):
  // undefined = no link intent (key drops out of the JSON), a UUID = linked
  // to that spine sample, null = explicit unlink (server mints fresh).
  strabo_id: undefined,
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

const { isDirty, resetDirty } = useFormDirty(form)

// ===== StraboSamples link state (Exp_StraboSamples_Linking.md) =====
const showLinkDialog = ref(false)
// Display info for the linked spine sample: { name, hasField, hasMicro,
// unresolved }. Populated by the picker selection or by hydration on load.
const linkedInfo = ref(null)

const linkedId = computed(() => form.value.strabo_id || null)
const linkedLabel = computed(() =>
  (linkedInfo.value && linkedInfo.value.name) || linkedId.value || '')
// Drill-down URL into the StraboSamples record (composite spine PK needs
// the owner pkey, carried on the fetched record).
const linkedUrl = computed(() => {
  if (!linkedId.value || !linkedInfo.value || !linkedInfo.value.owner) return null
  return `/samples/${linkedInfo.value.owner}/${encodeURIComponent(linkedId.value)}`
})
// Lock rule (D2): a higher-priority system (Field or Micro) manages the
// sample's identity; the server suppresses Exp spine writes in that case,
// so editable fields would silently lie.
const identityLocked = computed(() =>
  !!(linkedInfo.value && (linkedInfo.value.hasField || linkedInfo.value.hasMicro)))
const managedByLabel = computed(() => {
  if (!linkedInfo.value) return ''
  return linkedInfo.value.hasField ? 'StraboField' : 'StraboMicro'
})

// Resolve chip text + lock flags for a strabo_id that arrived with the data
// (edit round-trip, Load Data carryover). Degrades to an unlocked chip
// showing the raw id when the sample no longer resolves.
const hydrateLink = async (id) => {
  if (!id) {
    linkedInfo.value = null
    return
  }
  try {
    const record = await sampleLinkService.getSample(id)
    linkedInfo.value = record
      ? { name: record.name || id, owner: record.userpkey, hasField: !!record.field_data, hasMicro: !!record.micro_data }
      : { name: id, owner: null, hasField: false, hasMicro: false, unresolved: true }
  } catch (err) {
    linkedInfo.value = { name: id, owner: null, hasField: false, hasMicro: false, unresolved: true }
  }
}

// Picker selection: adopt the spine id and prefill (D2). Material Type is
// deliberately NOT prefilled — the LAPS and Field vocabularies are disjoint.
const handleSelectSample = (record) => {
  form.value.strabo_id = record.id
  linkedInfo.value = {
    name: record.name || record.id,
    owner: record.userpkey,
    hasField: !!record.field_data,
    hasMicro: !!record.micro_data
  }
  form.value.name = record.name || ''
  form.value.igsn = record.igsn || ''
  form.value.description = record.description || ''
  if (!form.value.id || form.value.id.trim() === '') {
    form.value.id = record.name || ''
  }
  if (record.latitude !== null && record.latitude !== undefined) {
    form.value.material.provenance.location.latitude = String(record.latitude)
  }
  if (record.longitude !== null && record.longitude !== undefined) {
    form.value.material.provenance.location.longitude = String(record.longitude)
  }
  if (record.parent) {
    form.value.parent.name = record.parent.name || form.value.parent.name
    form.value.parent.igsn = record.parent.igsn || form.value.parent.igsn
    form.value.parent.description = record.parent.description || form.value.parent.description
  }
  showLinkDialog.value = false
}

// Unlink (D3): explicit null tells the server to detach this experiment
// from the spine sample and mint a fresh identity on save. Field values
// stay as they are.
const handleUnlink = () => {
  form.value.strabo_id = null
  linkedInfo.value = null
}

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      strabo_id: data.strabo_id || undefined,
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
  resetDirty()
  // Hydrate the linked-sample chip (name + lock flags) for an id that rode
  // in with the data — including Load Data carryover, which must surface
  // the link visibly rather than silently (D3). Async, does not touch form.
  hydrateLink(data && data.strabo_id ? data.strabo_id : null)
}, { immediate: true, deep: true })

// Validate form and return array of error messages
const validateForm = () => {
  const errors = []

  // Sample Name is required
  if (!form.value.name || form.value.name.trim() === '') {
    errors.push('Sample Name cannot be blank.')
  }

  // Sample ID is required
  if (!form.value.id || form.value.id.trim() === '') {
    errors.push('Sample ID cannot be blank.')
  }

  // Material Type is required
  if (!form.value.material.material.type || form.value.material.material.type.trim() === '') {
    errors.push('Material Type cannot be blank.')
  }

  // Material Name is required
  if (!form.value.material.material.name || form.value.material.material.name.trim() === '') {
    errors.push('Material Name cannot be blank.')
  }

  // If mineralogy phases exist, mineral name is required for each
  form.value.material.composition.forEach((phase, idx) => {
    if (!phase.mineral || phase.mineral.trim() === '') {
      errors.push(`Mineral cannot be blank for Phase ${idx + 1}.`)
    }
  })

  // If parameters exist, variable is required for each
  form.value.parameters.forEach((param, idx) => {
    if (!param.control || param.control.trim() === '') {
      errors.push(`Variable cannot be blank for Parameter ${idx + 1}.`)
    }
  })

  return errors
}

const isValid = computed(() => {
  return validateForm().length === 0
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
  const errors = validateForm()
  if (errors.length > 0) {
    toast.add({
      severity: 'error',
      summary: 'Validation Error',
      detail: errors.join('\n'),
      life: 5000
    })
    return false
  }
  emit('submit', form.value)
  return true
}

defineExpose({ isDirty, trySubmit: handleSubmit })
</script>

<style scoped>
.sample-form {
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

/* "Link Sample From StraboSamples" — matches the StraboMicro treatment
   (accent text on an outlined full-width button). strabo-* are Tailwind
   tokens, not CSS vars, so use the palette's accent hex directly here
   (tailwind.config.js strabo.accent). The app's global button theme paints
   buttons solid red, so the outlined look needs !important. */
.link-samples-btn.p-button {
  background: transparent !important;
  color: #f4511e !important;
  border: 1px solid #f4511e !important;
}

.link-samples-btn.p-button:hover {
  background: rgba(244, 81, 30, 0.12) !important;
  color: #f4511e !important;
}

.linked-chip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  background: var(--p-surface-800, #27272a);
}

.linked-chip .pi-link {
  color: #f4511e;
}

.linked-chip-link {
  color: #f4511e;
  text-decoration: none;
}

.linked-chip-link:hover {
  text-decoration: underline;
}
</style>
