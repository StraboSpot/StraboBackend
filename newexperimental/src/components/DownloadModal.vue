<template>
  <Dialog
    :visible="visible"
    modal
    header="DOWNLOAD EXPERIMENT"
    :style="{ width: '900px' }"
    :closable="true"
    @update:visible="$emit('close')"
    :pt="{
      root: { class: 'dark-mode' },
      header: { class: 'border-b border-surface-700' },
      headerTitle: { class: 'text-xl flex-1 text-center' },
      content: { class: 'p-4' }
    }"
  >
    <!-- JSON Display -->
    <fieldset class="json-fieldset">
      <legend>EXPERIMENT JSON</legend>
      <textarea
        ref="jsonTextarea"
        :value="jsonString"
        readonly
        class="json-textarea"
      ></textarea>
    </fieldset>

    <!-- Action Buttons -->
    <div class="flex justify-center gap-6 mt-6">
      <Button
        label="Cancel"
        severity="danger"
        @click="$emit('close')"
        class="action-btn"
      />
      <Button
        label="Copy"
        severity="danger"
        @click="handleCopy"
        class="action-btn"
      />
      <Button
        label="Save"
        severity="danger"
        @click="handleSave"
        class="action-btn"
      />
    </div>

    <!-- Copy Notification -->
    <div v-if="showCopied" class="copied-notification">
      Data Copied to Clipboard.
    </div>
  </Dialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  data: {
    type: Object,
    default: () => ({})
  },
  filename: {
    type: String,
    default: 'experiment'
  }
})

const emit = defineEmits(['close'])

const jsonTextarea = ref(null)
const showCopied = ref(false)

// Format JSON with tabs for display
const jsonString = computed(() => {
  return JSON.stringify(props.data, null, '\t')
})

// Sanitize filename (replace non-word characters with underscores)
const sanitizedFilename = computed(() => {
  const name = props.filename || 'experiment'
  return name.replace(/(\W+)/gi, '_')
})

// Copy JSON to clipboard
const handleCopy = async () => {
  try {
    await navigator.clipboard.writeText(jsonString.value)
    showCopied.value = true
    setTimeout(() => {
      showCopied.value = false
      emit('close')
    }, 1500)
  } catch (err) {
    // Fallback for older browsers
    if (jsonTextarea.value) {
      jsonTextarea.value.select()
      document.execCommand('copy')
      showCopied.value = true
      setTimeout(() => {
        showCopied.value = false
        emit('close')
      }, 1500)
    }
  }
}

// Save JSON as file
const handleSave = () => {
  const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(jsonString.value)
  const downloadAnchor = document.createElement('a')
  downloadAnchor.setAttribute('href', dataStr)
  downloadAnchor.setAttribute('download', sanitizedFilename.value + '.json')
  downloadAnchor.click()
  emit('close')
}
</script>

<style scoped>
.json-fieldset {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 1rem 1.25rem;
  margin: 0;
}

.json-fieldset legend {
  font-size: 0.875rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-300);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.json-textarea {
  width: 100%;
  height: 450px;
  padding: 12px 16px;
  box-sizing: border-box;
  border: 1px solid var(--p-surface-500);
  border-radius: 8px;
  background-color: var(--p-surface-800);
  color: var(--p-surface-100);
  font-size: 0.875rem;
  font-family: monospace;
  resize: none;
  overflow: auto;
}

.json-textarea:focus {
  outline: none;
  border-color: var(--p-primary-color);
}

.action-btn {
  min-width: 120px;
  font-weight: 600;
}

.copied-notification {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background-color: #f4511e;
  color: white;
  padding: 2rem 3rem;
  font-size: 1.5rem;
  font-weight: 600;
  border-radius: 8px;
  z-index: 99999;
  animation: fadeOut 1.5s ease-in-out forwards;
}

@keyframes fadeOut {
  0% {
    opacity: 1;
  }
  70% {
    opacity: 1;
  }
  100% {
    opacity: 0;
  }
}
</style>
