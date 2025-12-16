<template>
  <div class="parameter-row">
    <div class="parameter-fields">
      <!-- Name/Type/Variable field -->
      <div class="field" :class="nameFieldClass">
        <label class="text-sm">{{ nameLabel }}</label>
        <Select
          v-if="nameOptions && nameOptions.length"
          :modelValue="modelValue[nameField]"
          @update:modelValue="updateField(nameField, $event)"
          :options="nameOptions"
          :placeholder="namePlaceholder"
          showClear
          filter
          class="w-full"
        />
        <InputText
          v-else
          :modelValue="modelValue[nameField]"
          @update:modelValue="updateField(nameField, $event)"
          :placeholder="namePlaceholder"
          class="w-full"
        />
      </div>

      <!-- Min field (optional) -->
      <div v-if="showMin" class="field w-20">
        <label class="text-sm">Min</label>
        <InputText
          :modelValue="modelValue.min"
          @update:modelValue="updateField('min', $event)"
          class="w-full"
        />
      </div>

      <!-- Max field (optional) -->
      <div v-if="showMax" class="field w-20">
        <label class="text-sm">Max</label>
        <InputText
          :modelValue="modelValue.max"
          @update:modelValue="updateField('max', $event)"
          class="w-full"
        />
      </div>

      <!-- Value field (optional) -->
      <div v-if="showValue" class="field w-24">
        <label class="text-sm">Value</label>
        <InputText
          :modelValue="modelValue.value"
          @update:modelValue="updateField('value', $event)"
          class="w-full"
        />
      </div>

      <!-- Error field (optional) -->
      <div v-if="showError" class="field w-20">
        <label class="text-sm">Error</label>
        <InputText
          :modelValue="modelValue.error"
          @update:modelValue="updateField('error', $event)"
          class="w-full"
        />
      </div>

      <!-- Unit field -->
      <div class="field w-28">
        <label class="text-sm">Unit</label>
        <Select
          :modelValue="modelValue.unit"
          @update:modelValue="updateField('unit', $event)"
          :options="unitOptions"
          placeholder="Select..."
          showClear
          filter
          class="w-full"
        />
      </div>

      <!-- Prefix field -->
      <div class="field w-24">
        <label class="text-sm">Prefix</label>
        <Select
          :modelValue="modelValue.prefix"
          @update:modelValue="updateField('prefix', $event)"
          :options="prefixOptions"
          class="w-full"
        />
      </div>

      <!-- Note field -->
      <div class="field flex-1 min-w-40">
        <label class="text-sm">{{ noteLabel }}</label>
        <InputText
          :modelValue="modelValue.note"
          @update:modelValue="updateField('note', $event)"
          :placeholder="notePlaceholder"
          class="w-full"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { UNIT_TYPES, UNIT_PREFIXES } from '@/schemas/laps-enums'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true
  },
  // Name/Type field configuration
  nameField: {
    type: String,
    default: 'type' // Could be 'type', 'control', 'variable'
  },
  nameLabel: {
    type: String,
    default: 'Name'
  },
  nameOptions: {
    type: Array,
    default: null // null = text input, array = dropdown
  },
  namePlaceholder: {
    type: String,
    default: 'Select...'
  },
  nameFieldClass: {
    type: String,
    default: 'flex-1 min-w-32'
  },
  // Optional field visibility
  showMin: {
    type: Boolean,
    default: false
  },
  showMax: {
    type: Boolean,
    default: false
  },
  showValue: {
    type: Boolean,
    default: false
  },
  showError: {
    type: Boolean,
    default: false
  },
  // Note field configuration
  noteLabel: {
    type: String,
    default: 'Note'
  },
  notePlaceholder: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:modelValue'])

const unitOptions = UNIT_TYPES
const prefixOptions = computed(() => ['-', ...UNIT_PREFIXES])

function updateField(field, value) {
  emit('update:modelValue', {
    ...props.modelValue,
    [field]: value
  })
}
</script>

<style scoped>
.parameter-row {
  width: 100%;
}

.parameter-fields {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: flex-end;
}

.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}
</style>
