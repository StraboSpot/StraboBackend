<template>
  <form @submit.prevent="handleSubmit">
    <!-- Basic Information -->
    <CollapsibleSection title="Basic Information" :default-open="true">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="name" class="form-label">Facility Name *</label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            class="form-input"
            required
            placeholder="e.g., Rock Mechanics Laboratory"
          />
        </div>

        <div>
          <label for="type" class="form-label">Facility Type *</label>
          <select id="type" v-model="form.type" class="form-select" required>
            <option value="">Select type...</option>
            <option v-for="t in FACILITY_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <div>
          <label for="institute" class="form-label">Institute *</label>
          <input
            id="institute"
            v-model="form.institute"
            type="text"
            class="form-input"
            required
            placeholder="e.g., Massachusetts Institute of Technology"
          />
        </div>

        <div>
          <label for="department" class="form-label">Department</label>
          <input
            id="department"
            v-model="form.department"
            type="text"
            class="form-input"
            placeholder="e.g., Earth, Atmospheric and Planetary Sciences"
          />
        </div>

        <div>
          <label for="website" class="form-label">Website</label>
          <input
            id="website"
            v-model="form.website"
            type="url"
            class="form-input"
            placeholder="https://..."
          />
        </div>

        <div class="md:col-span-2">
          <label for="description" class="form-label">Description</label>
          <textarea
            id="description"
            v-model="form.description"
            class="form-textarea"
            rows="3"
            placeholder="Brief description of the facility..."
          ></textarea>
        </div>
      </div>
    </CollapsibleSection>

    <!-- Address -->
    <CollapsibleSection title="Address" class="mt-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="building" class="form-label">Building</label>
          <input
            id="building"
            v-model="form.address.building"
            type="text"
            class="form-input"
            placeholder="Building name or number"
          />
        </div>

        <div>
          <label for="street" class="form-label">Street</label>
          <input
            id="street"
            v-model="form.address.street"
            type="text"
            class="form-input"
            placeholder="Street address"
          />
        </div>

        <div>
          <label for="city" class="form-label">City</label>
          <input
            id="city"
            v-model="form.address.city"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="state" class="form-label">State/Province</label>
          <input
            id="state"
            v-model="form.address.state"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="country" class="form-label">Country</label>
          <input
            id="country"
            v-model="form.address.country"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="postcode" class="form-label">Postal Code</label>
          <input
            id="postcode"
            v-model="form.address.postcode"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="latitude" class="form-label">Latitude</label>
          <input
            id="latitude"
            v-model="form.address.latitude"
            type="number"
            step="any"
            class="form-input"
            placeholder="-90 to 90"
          />
        </div>

        <div>
          <label for="longitude" class="form-label">Longitude</label>
          <input
            id="longitude"
            v-model="form.address.longitude"
            type="number"
            step="any"
            class="form-input"
            placeholder="-180 to 180"
          />
        </div>
      </div>
    </CollapsibleSection>

    <!-- Contact -->
    <CollapsibleSection title="Contact Person" class="mt-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label for="firstname" class="form-label">First Name</label>
          <input
            id="firstname"
            v-model="form.contact.firstname"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="lastname" class="form-label">Last Name</label>
          <input
            id="lastname"
            v-model="form.contact.lastname"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="affiliation" class="form-label">Affiliation</label>
          <input
            id="affiliation"
            v-model="form.contact.affiliation"
            type="text"
            class="form-input"
          />
        </div>

        <div>
          <label for="email" class="form-label">Email</label>
          <input
            id="email"
            v-model="form.contact.email"
            type="email"
            class="form-input"
          />
        </div>

        <div>
          <label for="phone" class="form-label">Phone</label>
          <input
            id="phone"
            v-model="form.contact.phone"
            type="tel"
            class="form-input"
          />
        </div>

        <div>
          <label for="contactWebsite" class="form-label">Website</label>
          <input
            id="contactWebsite"
            v-model="form.contact.website"
            type="url"
            class="form-input"
            placeholder="https://..."
          />
        </div>

        <div>
          <label for="orcid" class="form-label">ORCID ID</label>
          <input
            id="orcid"
            v-model="form.contact.id"
            type="text"
            class="form-input"
            placeholder="0000-0000-0000-0000"
          />
        </div>
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
import { useToast } from 'primevue/usetoast'
import CollapsibleSection from '@/components/CollapsibleSection.vue'
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
