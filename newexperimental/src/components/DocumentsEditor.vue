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
      <template #detail="{ item, index, update }">
        <div class="document-form">
          <!-- Row 1: Type, Format, File Upload, Document ID -->
          <div class="document-row">
            <!-- Document Type -->
            <div class="field field-type">
              <label class="text-sm">Document Type <span class="text-red-500">*</span></label>
              <Select
                :modelValue="item.type"
                @update:modelValue="handleTypeChange(item, $event, update)"
                :options="documentTypes"
                placeholder="Select..."
                class="w-full"
              />
            </div>

            <!-- Document Format -->
            <div class="field field-format">
              <label class="text-sm">Document Format <span class="text-red-500">*</span></label>
              <Select
                :modelValue="item.format"
                @update:modelValue="handleFormatChange(item, $event, update)"
                :options="documentFormats"
                placeholder="Select..."
                class="w-full"
              />
            </div>

            <!-- File Upload -->
            <div class="field field-file">
              <DocumentUploader
                :uuid="item.uuid"
                :modelValue="item.path"
                @update:modelValue="update('path', $event)"
                @uploaded="handleFileUploaded(item, $event, update)"
              />
            </div>

            <!-- Document ID -->
            <div class="field field-id">
              <label class="text-sm">Document ID</label>
              <InputText
                :modelValue="item.documentId"
                @update:modelValue="update('documentId', $event)"
                placeholder=""
                class="w-full"
              />
            </div>
          </div>

          <!-- Other Type/Format inputs (shown below main row when needed) -->
          <div v-if="item.type === 'Other' || item.format === 'Other'" class="other-fields-row">
            <div v-if="item.type === 'Other'" class="field field-other">
              <label class="text-sm">Other Type</label>
              <InputText
                :modelValue="item.otherType"
                @update:modelValue="update('otherType', $event)"
                placeholder="doc type..."
                class="w-full"
              />
            </div>
            <div v-if="item.format === 'Other'" class="field field-other">
              <label class="text-sm">Other Format</label>
              <InputText
                :modelValue="item.otherFormat"
                @update:modelValue="update('otherFormat', $event)"
                placeholder="format..."
                class="w-full"
              />
            </div>
          </div>

          <!-- Row 2: Description -->
          <div class="mt-3">
            <div class="field">
              <label class="text-sm">Description</label>
              <Textarea
                :modelValue="item.description"
                @update:modelValue="update('description', $event)"
                rows="2"
                class="w-full"
              />
            </div>
          </div>
        </div>
      </template>
    </ListDetailEditor>
  </div>
</template>

<script setup>
import { v4 as uuidv4 } from 'uuid'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import DocumentUploader from '@/components/DocumentUploader.vue'
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
  }
})

defineEmits(['update:modelValue'])

const documentTypes = DOCUMENT_TYPES
const documentFormats = DOCUMENT_FORMATS

// Create a new document with a pre-generated UUID (matching old app behavior)
const defaultDocument = () => ({
  uuid: uuidv4(),
  type: '',
  otherType: '',
  format: '',
  otherFormat: '',
  path: '',
  documentId: '',
  description: ''
})

function getDocumentLabel(item, idx) {
  // Use type for label, or "Other" type if specified
  const displayType = item.type === 'Other' && item.otherType
    ? item.otherType
    : item.type

  if (displayType) {
    return displayType
  }
  return `Document ${idx + 1}`
}

function handleTypeChange(item, value, update) {
  update('type', value)
  // Clear otherType if not "Other"
  if (value !== 'Other') {
    update('otherType', '')
  }
}

function handleFormatChange(item, value, update) {
  update('format', value)
  // Clear otherFormat if not "Other"
  if (value !== 'Other') {
    update('otherFormat', '')
  }
}

function handleFileUploaded(item, event, update) {
  // File was successfully uploaded - the path is already updated
  // We could do additional things here if needed
}
</script>

<style scoped>
.document-form {
  padding: 0.5rem 0;
}

.document-row {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.field {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}

.field label {
  margin-bottom: 2px;
  color: var(--p-surface-300);
}

.field-type {
  width: 140px;
}

.field-format {
  width: 130px;
}

.field-file {
  flex: 1;
  min-width: 200px;
  flex-shrink: 1;
}

.field-id {
  width: 120px;
}

.other-fields-row {
  display: flex;
  gap: 0.75rem;
  margin-top: 0.5rem;
  padding-left: 0;
}

.field-other {
  width: 150px;
}
</style>
