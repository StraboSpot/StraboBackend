<template>
  <form @submit.prevent="handleSubmit" class="facility-apparatus-form">
    <!-- Repository Selection Option -->
    <fieldset class="form-section">
      <legend>SELECT FROM REPOSITORY</legend>
      <div class="flex gap-4 items-end">
        <div class="field flex-1">
          <label class="text-sm">Facility</label>
          <Select
            v-model="selectedFacilityId"
            :options="facilityOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Select facility..."
            showClear
            filter
            @change="handleFacilitySelect"
            class="w-full"
          />
        </div>
        <div class="field flex-1">
          <label class="text-sm">Apparatus</label>
          <Select
            v-model="selectedApparatusId"
            :options="apparatusOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Select apparatus..."
            showClear
            filter
            :disabled="!selectedFacilityId"
            @change="handleApparatusSelect"
            class="w-full"
          />
        </div>
        <Button
          type="button"
          label="Load"
          :disabled="!selectedApparatusId"
          @click="loadFromRepository"
          class="mb-1"
        />
      </div>
      <p class="text-xs text-surface-400 mt-2">
        Select a facility and apparatus from the repository to auto-fill the form, or enter details manually below.
      </p>
    </fieldset>

    <!-- Facility Info Section -->
    <fieldset class="form-section">
      <legend>FACILITY INFO</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Facility Name *</label>
          <InputText v-model="form.facility.name" :invalid="!form.facility.name" />
        </div>
        <div class="field">
          <label class="text-sm">Facility Type *</label>
          <Select
            v-model="form.facility.type"
            :options="FACILITY_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field" v-if="isOther(form.facility.type)">
          <label class="text-sm">Other Facility Type</label>
          <InputText v-model="form.facility.other_type" placeholder="Enter other type..." />
        </div>
        <div class="field">
          <label class="text-sm">Facility ID</label>
          <InputText v-model="form.facility.id" />
        </div>
        <div class="field">
          <label class="text-sm">Website</label>
          <InputText v-model="form.facility.website" placeholder="https://..." />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Institute Name *</label>
          <InputText v-model="form.facility.institute" :invalid="!form.facility.institute" />
        </div>
        <div class="field">
          <label class="text-sm">Department</label>
          <InputText v-model="form.facility.department" />
        </div>
        <div class="field md:col-span-2">
          <label class="text-sm">Description</label>
          <InputText v-model="form.facility.description" />
        </div>
      </div>
    </fieldset>

    <!-- Facility Address Section -->
    <fieldset class="form-section">
      <legend>FACILITY ADDRESS</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Street + Number</label>
          <InputText v-model="form.facility.address.street" />
        </div>
        <div class="field">
          <label class="text-sm">Building - Apt</label>
          <InputText v-model="form.facility.address.building" />
        </div>
        <div class="field">
          <label class="text-sm">Postal Code</label>
          <InputText v-model="form.facility.address.postcode" />
        </div>
        <div class="field">
          <label class="text-sm">City</label>
          <InputText v-model="form.facility.address.city" />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">State</label>
          <InputText v-model="form.facility.address.state" />
        </div>
        <div class="field">
          <label class="text-sm">Country</label>
          <InputText v-model="form.facility.address.country" />
        </div>
        <div class="field">
          <label class="text-sm">Latitude</label>
          <InputText v-model="form.facility.address.latitude" placeholder="Decimal degrees" />
        </div>
        <div class="field">
          <label class="text-sm">Longitude</label>
          <InputText v-model="form.facility.address.longitude" placeholder="Decimal degrees" />
        </div>
      </div>
    </fieldset>

    <!-- Facility Contact Section -->
    <fieldset class="form-section">
      <legend>FACILITY CONTACT</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">First Name</label>
          <InputText v-model="form.facility.contact.firstname" />
        </div>
        <div class="field">
          <label class="text-sm">Last Name</label>
          <InputText v-model="form.facility.contact.lastname" />
        </div>
        <div class="field">
          <label class="text-sm">Affiliation</label>
          <Select
            v-model="form.facility.contact.affiliation"
            :options="AFFILIATION_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-sm">Email</label>
          <InputText v-model="form.facility.contact.email" type="email" />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Phone</label>
          <InputText v-model="form.facility.contact.phone" />
        </div>
        <div class="field">
          <label class="text-sm">Website</label>
          <InputText v-model="form.facility.contact.website" placeholder="https://..." />
        </div>
        <div class="field">
          <label class="text-sm">ORCID ID</label>
          <InputText v-model="form.facility.contact.id" placeholder="0000-0000-0000-0000" />
        </div>
      </div>
    </fieldset>

    <!-- Apparatus Info Section -->
    <fieldset class="form-section">
      <legend>APPARATUS INFO</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Apparatus Name *</label>
          <InputText v-model="form.apparatus.name" :invalid="!form.apparatus.name" />
        </div>
        <div class="field">
          <label class="text-sm">Apparatus Type *</label>
          <Select
            v-model="form.apparatus.type"
            :options="APPARATUS_TYPES"
            placeholder="Select..."
            showClear
            filter
          />
        </div>
        <div class="field" v-if="isOtherApparatus(form.apparatus.type)">
          <label class="text-sm">Other Apparatus Type</label>
          <InputText v-model="form.apparatus.other_type" placeholder="Enter other type..." />
        </div>
        <div class="field">
          <label class="text-sm">Location</label>
          <InputText v-model="form.apparatus.location" placeholder="Room/Building" />
        </div>
        <div class="field">
          <label class="text-sm">Apparatus ID</label>
          <InputText v-model="form.apparatus.id" />
        </div>
      </div>
      <div class="grid grid-cols-1 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Description</label>
          <Textarea v-model="form.apparatus.description" rows="3" class="w-full" />
        </div>
      </div>
    </fieldset>

    <!-- Apparatus Features Section -->
    <fieldset class="form-section">
      <legend>APPARATUS FEATURES</legend>
      <p class="text-xs text-surface-400 mb-3">Select all applicable test capabilities:</p>
      <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
        <label
          v-for="feature in APPARATUS_FEATURES"
          :key="feature"
          class="feature-checkbox-label"
        >
          <input
            type="checkbox"
            :value="feature"
            v-model="form.apparatus.features"
            class="feature-checkbox"
          />
          <span class="text-sm">{{ feature }}</span>
        </label>
      </div>
    </fieldset>

    <!-- Apparatus Parameters Section -->
    <fieldset class="form-section">
      <legend>APPARATUS PARAMETERS</legend>
      <ListDetailEditor
        title=""
        add-label="Add Parameter"
        :items="form.apparatus.parameters"
        :default-item="defaultParameter"
        :label-function="(item, idx) => item.type || `Param ${idx + 1}`"
        @update:items="form.apparatus.parameters = $event"
      >
        <template #detail="{ item, update }">
          <div class="flex gap-3 flex-wrap">
            <div class="field flex-1 min-w-32">
              <label class="text-sm">Name</label>
              <Select
                :modelValue="item.type"
                @update:modelValue="update('type', $event)"
                :options="APPARATUS_PARAMETER_TYPES"
                placeholder="Select..."
                showClear
              />
            </div>
            <div class="field w-24">
              <label class="text-sm">Min</label>
              <InputText
                :modelValue="item.min"
                @update:modelValue="update('min', $event)"
              />
            </div>
            <div class="field w-24">
              <label class="text-sm">Max</label>
              <InputText
                :modelValue="item.max"
                @update:modelValue="update('max', $event)"
              />
            </div>
            <div class="field w-28">
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
            <div class="field flex-1 min-w-40">
              <label class="text-sm">Note</label>
              <InputText
                :modelValue="item.note"
                @update:modelValue="update('note', $event)"
              />
            </div>
          </div>
        </template>
      </ListDetailEditor>
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
        label="Save Facility & Apparatus"
        :disabled="!isValid"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import { facilityService, apparatusService } from '@/services/api'
import {
  FACILITY_TYPES,
  APPARATUS_TYPES,
  APPARATUS_FEATURES,
  PARAMETER_TYPES,
  UNIT_TYPES,
  UNIT_PREFIXES
} from '@/schemas/laps-enums'

// Apparatus parameter types (different from sample parameter types)
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

const AFFILIATION_TYPES = [
  'Student',
  'Researcher',
  'Lab Manager',
  'Principal Investigator',
  'Technical Associate',
  'Faculty',
  'Professor',
  'Visitor',
  'Service User',
  'External User'
]

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const prefixOptions = computed(() => ['-', ...UNIT_PREFIXES])

// Helper to check if a value is "Other"
const isOther = (value) => value && value.toLowerCase() === 'other'
const isOtherApparatus = (value) => value && value.toLowerCase() === 'other apparatus'

// Repository selection state
const facilities = ref([])
const selectedFacilityId = ref(null)
const selectedApparatusId = ref(null)
const loading = ref(false)

// Facility options for dropdown
const facilityOptions = computed(() => {
  return facilities.value.map(f => ({
    label: `${f.name} (${f.institute || 'No Institute'})`,
    value: f.pkey
  }))
})

// Apparatus options for selected facility
const apparatusOptions = computed(() => {
  if (!selectedFacilityId.value) return []
  const facility = facilities.value.find(f => f.pkey === selectedFacilityId.value)
  if (!facility?.apparatuses) return []
  return facility.apparatuses.map(a => ({
    label: `${a.name} (${a.type || 'No Type'})`,
    value: a.pkey
  }))
})

// Create empty form structure
const createEmptyForm = () => ({
  facility: {
    name: '',
    type: '',
    other_type: '',
    id: '',
    website: '',
    institute: '',
    department: '',
    description: '',
    address: {
      street: '',
      building: '',
      postcode: '',
      city: '',
      state: '',
      country: '',
      latitude: '',
      longitude: ''
    },
    contact: {
      firstname: '',
      lastname: '',
      affiliation: '',
      email: '',
      phone: '',
      website: '',
      id: ''
    }
  },
  apparatus: {
    name: '',
    type: '',
    other_type: '',
    location: '',
    id: '',
    description: '',
    features: [],
    parameters: [],
    documents: []
  }
})

const form = ref(createEmptyForm())

// Default parameter factory for ListDetailEditor
const defaultParameter = () => ({
  type: '',
  min: '',
  max: '',
  unit: '',
  prefix: '-',
  note: ''
})

// Load facilities for repository selection
const loadFacilities = async () => {
  try {
    loading.value = true
    const response = await facilityService.listWithApparatuses()
    facilities.value = response.facilities || []
  } catch (error) {
    console.error('Failed to load facilities:', error)
  } finally {
    loading.value = false
  }
}

// Handle facility selection change
const handleFacilitySelect = () => {
  selectedApparatusId.value = null
}

// Handle apparatus selection change
const handleApparatusSelect = () => {
  // Just update the selection, don't auto-load
}

// Load data from repository
const loadFromRepository = async () => {
  if (!selectedFacilityId.value || !selectedApparatusId.value) return

  try {
    loading.value = true

    // Fetch full facility and apparatus data
    const [facilityData, apparatusData] = await Promise.all([
      facilityService.get(selectedFacilityId.value),
      apparatusService.get(selectedApparatusId.value)
    ])

    // Populate facility form
    if (facilityData) {
      form.value.facility = {
        name: facilityData.name || '',
        type: facilityData.type || '',
        other_type: facilityData.other_type || '',
        id: facilityData.id || '',
        website: facilityData.website || '',
        institute: facilityData.institute || '',
        department: facilityData.department || '',
        description: facilityData.description || '',
        address: {
          street: facilityData.address?.street || '',
          building: facilityData.address?.building || '',
          postcode: facilityData.address?.postcode || '',
          city: facilityData.address?.city || '',
          state: facilityData.address?.state || '',
          country: facilityData.address?.country || '',
          latitude: facilityData.address?.latitude || '',
          longitude: facilityData.address?.longitude || ''
        },
        contact: {
          firstname: facilityData.contact?.firstname || '',
          lastname: facilityData.contact?.lastname || '',
          affiliation: facilityData.contact?.affiliation || '',
          email: facilityData.contact?.email || '',
          phone: facilityData.contact?.phone || '',
          website: facilityData.contact?.website || '',
          id: facilityData.contact?.id || ''
        }
      }
    }

    // Populate apparatus form
    if (apparatusData) {
      form.value.apparatus = {
        name: apparatusData.name || '',
        type: apparatusData.type || '',
        other_type: apparatusData.other_type || '',
        location: apparatusData.location || '',
        id: apparatusData.id || '',
        description: apparatusData.description || '',
        features: apparatusData.features || [],
        parameters: apparatusData.parameters?.map(p => ({ ...p })) || [],
        documents: apparatusData.documents?.map(d => ({ ...d })) || []
      }
    }
  } catch (error) {
    console.error('Failed to load from repository:', error)
  } finally {
    loading.value = false
  }
}

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      facility: {
        name: data.facility?.name || '',
        type: data.facility?.type || '',
        other_type: data.facility?.other_type || '',
        id: data.facility?.id || '',
        website: data.facility?.website || '',
        institute: data.facility?.institute || '',
        department: data.facility?.department || '',
        description: data.facility?.description || '',
        address: {
          street: data.facility?.address?.street || '',
          building: data.facility?.address?.building || '',
          postcode: data.facility?.address?.postcode || '',
          city: data.facility?.address?.city || '',
          state: data.facility?.address?.state || '',
          country: data.facility?.address?.country || '',
          latitude: data.facility?.address?.latitude || '',
          longitude: data.facility?.address?.longitude || ''
        },
        contact: {
          firstname: data.facility?.contact?.firstname || '',
          lastname: data.facility?.contact?.lastname || '',
          affiliation: data.facility?.contact?.affiliation || '',
          email: data.facility?.contact?.email || '',
          phone: data.facility?.contact?.phone || '',
          website: data.facility?.contact?.website || '',
          id: data.facility?.contact?.id || ''
        }
      },
      apparatus: {
        name: data.apparatus?.name || '',
        type: data.apparatus?.type || '',
        other_type: data.apparatus?.other_type || '',
        location: data.apparatus?.location || '',
        id: data.apparatus?.id || '',
        description: data.apparatus?.description || '',
        features: data.apparatus?.features?.slice() || [],
        parameters: data.apparatus?.parameters?.map(p => ({ ...p })) || [],
        documents: data.apparatus?.documents?.map(d => ({ ...d })) || []
      }
    }
  }
}, { immediate: true, deep: true })

const isValid = computed(() => {
  return (
    form.value.facility.name.trim().length > 0 &&
    form.value.facility.institute.trim().length > 0 &&
    form.value.apparatus.name.trim().length > 0
  )
})

function handleSubmit() {
  if (!isValid.value) return
  emit('submit', form.value)
}

onMounted(() => {
  loadFacilities()
})
</script>

<style scoped>
.facility-apparatus-form {
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

/* Feature checkboxes - override site styles */
.feature-checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  padding: 0.25rem;
  border-radius: 4px;
}

.feature-checkbox-label:hover {
  background-color: var(--p-surface-700);
}

.feature-checkbox {
  opacity: 1 !important;
  z-index: 1 !important;
  position: relative !important;
  appearance: auto !important;
  -webkit-appearance: checkbox !important;
  width: 16px !important;
  height: 16px !important;
  cursor: pointer;
  accent-color: var(--p-primary-color);
}
</style>
