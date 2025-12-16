<template>
  <div class="parameters-editor">
    <ListDetailEditor
      :title="title"
      :add-label="addLabel"
      :items="modelValue"
      :default-item="createDefaultItem"
      :label-function="getParameterLabel"
      @update:items="$emit('update:modelValue', $event)"
    >
      <template #detail="{ item, update }">
        <ParameterRow
          :modelValue="item"
          @update:modelValue="handleItemUpdate(item, $event, update)"
          :name-field="nameField"
          :name-label="nameLabel"
          :name-options="nameOptions"
          :name-placeholder="namePlaceholder"
          :show-min="showMin"
          :show-max="showMax"
          :show-value="showValue"
          :show-error="showError"
          :note-label="noteLabel"
          :note-placeholder="notePlaceholder"
        />
      </template>
    </ListDetailEditor>
  </div>
</template>

<script setup>
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import ParameterRow from '@/components/ParameterRow.vue'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  },
  title: {
    type: String,
    default: ''
  },
  addLabel: {
    type: String,
    default: 'Add Parameter'
  },
  // ParameterRow props passthrough
  nameField: {
    type: String,
    default: 'type'
  },
  nameLabel: {
    type: String,
    default: 'Name'
  },
  nameOptions: {
    type: Array,
    default: null
  },
  namePlaceholder: {
    type: String,
    default: 'Select...'
  },
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
  noteLabel: {
    type: String,
    default: 'Note'
  },
  notePlaceholder: {
    type: String,
    default: ''
  },
  // Default values for new items
  defaultPrefix: {
    type: String,
    default: '-'
  }
})

const emit = defineEmits(['update:modelValue'])

function createDefaultItem() {
  const item = {
    [props.nameField]: '',
    unit: '',
    prefix: props.defaultPrefix,
    note: ''
  }
  if (props.showMin) item.min = ''
  if (props.showMax) item.max = ''
  if (props.showValue) item.value = ''
  if (props.showError) item.error = ''
  return item
}

function getParameterLabel(item, idx) {
  const name = item[props.nameField]
  return name || `Parameter ${idx + 1}`
}

// Bridge between ParameterRow's full object update and ListDetailEditor's field-by-field update
function handleItemUpdate(oldItem, newItem, updateFn) {
  // Find which field changed and call update for each changed field
  for (const key in newItem) {
    if (newItem[key] !== oldItem[key]) {
      updateFn(key, newItem[key])
    }
  }
}
</script>
