<template>
  <form @submit.prevent="handleSubmit" class="facility-apparatus-form">
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
            :invalid="!form.facility.type"
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
      <AddressFields v-model="form.facility.address" />
    </fieldset>

    <!-- Facility Contact Section -->
    <fieldset class="form-section">
      <legend>FACILITY CONTACT</legend>
      <ContactFields v-model="form.facility.contact" />
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
            :invalid="!form.apparatus.type"
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
      <FeaturePills
        :features="APPARATUS_FEATURES"
        v-model="form.apparatus.features"
      />
    </fieldset>

    <!-- Apparatus Parameters Section -->
    <fieldset class="form-section">
      <legend>APPARATUS PARAMETERS</legend>
      <ParametersEditor
        v-model="form.apparatus.parameters"
        add-label="Add Parameter"
        :name-options="APPARATUS_PARAMETER_TYPES"
        :show-min="true"
        :show-max="true"
      />
    </fieldset>

    <!-- Apparatus Documents Section -->
    <fieldset class="form-section">
      <legend>APPARATUS DOCUMENTS</legend>
      <DocumentsEditor
        v-model="form.apparatus.documents"
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
        label="Save Facility & Apparatus"
        :disabled="!isValid"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import AddressFields from '@/components/AddressFields.vue'
import ContactFields from '@/components/ContactFields.vue'
import FeaturePills from '@/components/FeaturePills.vue'
import ParametersEditor from '@/components/ParametersEditor.vue'
import DocumentsEditor from '@/components/DocumentsEditor.vue'
import {
  FACILITY_TYPES,
  APPARATUS_TYPES,
  APPARATUS_FEATURES,
  PARAMETER_TYPES
} from '@/schemas/laps-enums'

// Use the correct parameter types from the old app
const APPARATUS_PARAMETER_TYPES = PARAMETER_TYPES

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const toast = useToast()

// Helper to check if a value is "Other"
const isOther = (value) => value && value.toLowerCase() === 'other'
const isOtherApparatus = (value) => value && value.toLowerCase() === 'other apparatus'

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

// Validate form and return array of error messages
const validateForm = () => {
  const errors = []

  // Facility Name is required
  if (!form.value.facility.name || form.value.facility.name.trim() === '') {
    errors.push('Facility Name cannot be blank.')
  }

  // Facility Type is required
  if (!form.value.facility.type || form.value.facility.type.trim() === '') {
    errors.push('Facility Type cannot be blank.')
  }

  // Institute Name is required
  if (!form.value.facility.institute || form.value.facility.institute.trim() === '') {
    errors.push('Institute Name cannot be blank.')
  }

  // Apparatus Name is required
  if (!form.value.apparatus.name || form.value.apparatus.name.trim() === '') {
    errors.push('Apparatus Name cannot be blank.')
  }

  // Apparatus Type is required
  if (!form.value.apparatus.type || form.value.apparatus.type.trim() === '') {
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
.facility-apparatus-form {
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
</style>
