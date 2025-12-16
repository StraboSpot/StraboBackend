<template>
  <div class="contact-fields">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="field">
        <label class="text-sm">First Name</label>
        <InputText
          :modelValue="modelValue.firstname"
          @update:modelValue="updateField('firstname', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Last Name</label>
        <InputText
          :modelValue="modelValue.lastname"
          @update:modelValue="updateField('lastname', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Affiliation</label>
        <Select
          :modelValue="modelValue.affiliation"
          @update:modelValue="updateField('affiliation', $event)"
          :options="affiliationOptions"
          placeholder="Select..."
          showClear
        />
      </div>
      <div class="field">
        <label class="text-sm">Email</label>
        <InputText
          :modelValue="modelValue.email"
          @update:modelValue="updateField('email', $event)"
          type="email"
        />
      </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
      <div class="field">
        <label class="text-sm">Phone</label>
        <InputText
          :modelValue="modelValue.phone"
          @update:modelValue="updateField('phone', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Website</label>
        <InputText
          :modelValue="modelValue.website"
          @update:modelValue="updateField('website', $event)"
          placeholder="https://..."
        />
      </div>
      <div class="field">
        <label class="text-sm">ORCID ID</label>
        <InputText
          :modelValue="modelValue.id"
          @update:modelValue="updateField('id', $event)"
          placeholder="0000-0000-0000-0000"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({
      firstname: '',
      lastname: '',
      affiliation: '',
      email: '',
      phone: '',
      website: '',
      id: ''
    })
  }
})

const emit = defineEmits(['update:modelValue'])

const affiliationOptions = [
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

function updateField(field, value) {
  emit('update:modelValue', {
    ...props.modelValue,
    [field]: value
  })
}
</script>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}
</style>
