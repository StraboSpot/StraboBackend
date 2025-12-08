<template>
  <form @submit.prevent="handleSubmit" class="facility-form">
    <!-- Basic Information -->
    <fieldset class="form-section">
      <legend>BASIC INFORMATION</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Facility Name *</label>
          <input
            v-model="form.name"
            type="text"
            class="form-input"
            placeholder="e.g., Rock Mechanics Laboratory"
          />
        </div>

        <div class="field">
          <label class="text-sm">Facility Type *</label>
          <select v-model="form.type" class="form-select">
            <option value="">Select type...</option>
            <option v-for="t in FACILITY_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <div class="field">
          <label class="text-sm">Institute *</label>
          <input
            v-model="form.institute"
            type="text"
            class="form-input"
            placeholder="e.g., Massachusetts Institute of Technology"
          />
        </div>

        <div class="field">
          <label class="text-sm">Department</label>
          <input
            v-model="form.department"
            type="text"
            class="form-input"
            placeholder="e.g., Earth, Atmospheric and Planetary Sciences"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Website</label>
          <input
            v-model="form.website"
            type="url"
            class="form-input"
            placeholder="https://..."
          />
        </div>

        <div class="field md:col-span-3">
          <label class="text-sm">Description</label>
          <input
            v-model="form.description"
            type="text"
            class="form-input"
            placeholder="Brief description of the facility..."
          />
        </div>
      </div>
    </fieldset>

    <!-- Address -->
    <fieldset class="form-section">
      <legend>ADDRESS</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">Building</label>
          <input
            v-model="form.address.building"
            type="text"
            class="form-input"
            placeholder="Building name or number"
          />
        </div>

        <div class="field">
          <label class="text-sm">Street</label>
          <input
            v-model="form.address.street"
            type="text"
            class="form-input"
            placeholder="Street address"
          />
        </div>

        <div class="field">
          <label class="text-sm">City</label>
          <input
            v-model="form.address.city"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">State/Province</label>
          <input
            v-model="form.address.state"
            type="text"
            class="form-input"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Country</label>
          <input
            v-model="form.address.country"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Postal Code</label>
          <input
            v-model="form.address.postcode"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Latitude</label>
          <input
            v-model="form.address.latitude"
            type="number"
            step="any"
            class="form-input"
            placeholder="-90 to 90"
          />
        </div>

        <div class="field">
          <label class="text-sm">Longitude</label>
          <input
            v-model="form.address.longitude"
            type="number"
            step="any"
            class="form-input"
            placeholder="-180 to 180"
          />
        </div>
      </div>
    </fieldset>

    <!-- Contact -->
    <fieldset class="form-section">
      <legend>CONTACT PERSON</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field">
          <label class="text-sm">First Name</label>
          <input
            v-model="form.contact.firstname"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Last Name</label>
          <input
            v-model="form.contact.lastname"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Affiliation</label>
          <input
            v-model="form.contact.affiliation"
            type="text"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Email</label>
          <input
            v-model="form.contact.email"
            type="email"
            class="form-input"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Phone</label>
          <input
            v-model="form.contact.phone"
            type="tel"
            class="form-input"
          />
        </div>

        <div class="field">
          <label class="text-sm">Website</label>
          <input
            v-model="form.contact.website"
            type="url"
            class="form-input"
            placeholder="https://..."
          />
        </div>

        <div class="field">
          <label class="text-sm">ORCID ID</label>
          <input
            v-model="form.contact.id"
            type="text"
            class="form-input"
            placeholder="0000-0000-0000-0000"
          />
        </div>
      </div>
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
import { FACILITY_TYPES } from '@/schemas/laps-enums'

const toast = useToast()

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
    default: 'Save Facility'
  }
})

const emit = defineEmits(['submit', 'cancel'])

// Form state with defaults
const form = ref({
  name: '',
  type: '',
  institute: '',
  department: '',
  website: '',
  description: '',
  address: {
    building: '',
    street: '',
    city: '',
    state: '',
    country: '',
    postcode: '',
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
})

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data) {
    form.value = {
      name: data.name || '',
      type: data.type || '',
      institute: data.institute || '',
      department: data.department || '',
      website: data.website || '',
      description: data.description || '',
      address: {
        building: data.address?.building || '',
        street: data.address?.street || '',
        city: data.address?.city || '',
        state: data.address?.state || '',
        country: data.address?.country || '',
        postcode: data.address?.postcode || '',
        latitude: data.address?.latitude || '',
        longitude: data.address?.longitude || ''
      },
      contact: {
        firstname: data.contact?.firstname || '',
        lastname: data.contact?.lastname || '',
        affiliation: data.contact?.affiliation || '',
        email: data.contact?.email || '',
        phone: data.contact?.phone || '',
        website: data.contact?.website || '',
        id: data.contact?.id || ''
      }
    }
  }
}, { immediate: true })

// Validate form and return array of error messages
const validateForm = () => {
  const errors = []
  if (!form.value.name || form.value.name.trim() === '') {
    errors.push('Facility Name cannot be blank.')
  }
  if (!form.value.type || form.value.type.trim() === '') {
    errors.push('Facility Type cannot be blank.')
  }
  if (!form.value.institute || form.value.institute.trim() === '') {
    errors.push('Institute Name cannot be blank.')
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
.facility-form {
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
