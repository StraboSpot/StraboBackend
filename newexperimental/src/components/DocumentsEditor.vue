<template>
  <div class="documents-editor">
    <ListDetailEditor
      :title="title"
      :add-label="addLabel"
      :items="modelValue"
      :default-item="defaultDocument"
      :label-function="getDocumentLabel"
      @update:items="$emit('update:modelValue', $event)"
    >
      <template #detail="{ item, update }">
        <div class="flex gap-3 flex-wrap">
          <div class="field w-32">
            <label class="text-sm">Type</label>
            <Select
              :modelValue="item.type"
              @update:modelValue="update('type', $event)"
              :options="documentTypes"
              placeholder="Select..."
              showClear
            />
          </div>
          <div class="field w-28">
            <label class="text-sm">Format</label>
            <Select
              :modelValue="item.format"
              @update:modelValue="update('format', $event)"
              :options="documentFormats"
              placeholder="Select..."
              showClear
            />
          </div>
          <div class="field flex-1 min-w-48">
            <label class="text-sm">File Path / URL</label>
            <InputText
              :modelValue="item.path"
              @update:modelValue="update('path', $event)"
              placeholder="Enter path or URL..."
            />
          </div>
          <div class="field flex-1 min-w-48">
            <label class="text-sm">Description</label>
            <InputText
              :modelValue="item.description"
              @update:modelValue="update('description', $event)"
              placeholder="Document description..."
            />
          </div>
        </div>
      </template>
    </ListDetailEditor>

    <!-- File upload placeholder -->
    <div v-if="showUpload" class="mt-4">
      <div class="upload-area">
        <p class="text-surface-400 text-sm">
          Drag files here or click to upload
        </p>
        <p class="text-surface-500 text-xs mt-1">
          (File upload functionality coming soon)
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import { DOCUMENT_TYPES, DOCUMENT_FORMATS } from '@/schemas/laps-enums'

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
    default: 'Add Document'
  },
  showUpload: {
    type: Boolean,
    default: false
  }
})

defineEmits(['update:modelValue'])

const documentTypes = DOCUMENT_TYPES
const documentFormats = DOCUMENT_FORMATS

const defaultDocument = () => ({
  type: '',
  format: '',
  path: '',
  description: ''
})

function getDocumentLabel(item, idx) {
  if (item.type && item.format) {
    return `${item.type} (${item.format})`
  }
  if (item.type) {
    return item.type
  }
  return `Document ${idx + 1}`
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

.upload-area {
  border: 2px dashed var(--p-surface-600);
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s;
}

.upload-area:hover {
  border-color: var(--p-surface-500);
}
</style>
