<template>
  <div class="address-fields">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="field">
        <label class="text-sm">Street + Number</label>
        <InputText
          :modelValue="modelValue.street"
          @update:modelValue="updateField('street', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Building - Apt</label>
        <InputText
          :modelValue="modelValue.building"
          @update:modelValue="updateField('building', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Postal Code</label>
        <InputText
          :modelValue="modelValue.postcode"
          @update:modelValue="updateField('postcode', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">City</label>
        <InputText
          :modelValue="modelValue.city"
          @update:modelValue="updateField('city', $event)"
        />
      </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
      <div class="field">
        <label class="text-sm">State</label>
        <InputText
          :modelValue="modelValue.state"
          @update:modelValue="updateField('state', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Country</label>
        <InputText
          :modelValue="modelValue.country"
          @update:modelValue="updateField('country', $event)"
        />
      </div>
      <div class="field">
        <label class="text-sm">Latitude</label>
        <InputText
          :modelValue="modelValue.latitude"
          @update:modelValue="updateField('latitude', $event)"
          placeholder="Decimal degrees"
        />
      </div>
      <div class="field">
        <label class="text-sm">Longitude</label>
        <InputText
          :modelValue="modelValue.longitude"
          @update:modelValue="updateField('longitude', $event)"
          placeholder="Decimal degrees"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import InputText from 'primevue/inputtext'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({
      street: '',
      building: '',
      postcode: '',
      city: '',
      state: '',
      country: '',
      latitude: '',
      longitude: ''
    })
  }
})

const emit = defineEmits(['update:modelValue'])

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
