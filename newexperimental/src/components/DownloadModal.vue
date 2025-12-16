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
    <!-- Format Selection Tabs -->
    <div class="format-tabs mb-4">
      <button
        :class="['format-tab', { active: selectedFormat === 'json' }]"
        @click="selectedFormat = 'json'"
      >
        JSON
      </button>
      <button
        :class="['format-tab', { active: selectedFormat === 'pdf' }]"
        @click="selectedFormat = 'pdf'"
      >
        PDF
      </button>
    </div>

    <!-- JSON Format Content -->
    <div v-if="selectedFormat === 'json'">
      <fieldset class="json-fieldset">
        <legend>EXPERIMENT JSON</legend>
        <textarea
          ref="jsonTextarea"
          :value="jsonString"
          readonly
          class="json-textarea"
        ></textarea>
      </fieldset>

      <!-- Action Buttons for JSON -->
      <div class="flex justify-center gap-6 mt-6">
        <Button
          label="Cancel"
          severity="secondary"
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
          label="Save JSON"
          severity="danger"
          @click="handleSaveJson"
          class="action-btn"
        />
      </div>
    </div>

    <!-- PDF Format Content -->
    <div v-if="selectedFormat === 'pdf'">
      <fieldset class="pdf-fieldset">
        <legend>PDF EXPORT</legend>
        <div class="pdf-info">
          <div class="pdf-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
              <polyline points="14 2 14 8 20 8"/>
              <path d="M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1"/>
              <path d="M14 18a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1-1-1v-1a1 1 0 0 0-1-1"/>
            </svg>
          </div>
          <div class="pdf-description">
            <h3>Export as PDF Document</h3>
            <p>Generate a formatted PDF report containing all experiment data including:</p>
            <ul>
              <li>Sample information and material details</li>
              <li>Facility and apparatus specifications</li>
              <li>Experimental setup and geometry</li>
              <li>DAQ configuration and channels</li>
              <li>Protocol steps and parameters</li>
              <li>Data and results</li>
              <li>Embedded images (where available locally)</li>
            </ul>
          </div>
        </div>
      </fieldset>

      <!-- Action Buttons for PDF -->
      <div class="flex justify-center gap-6 mt-6">
        <Button
          label="Cancel"
          severity="secondary"
          @click="$emit('close')"
          class="action-btn"
        />
        <Button
          label="Download PDF"
          severity="danger"
          @click="handleDownloadPdf"
          :loading="pdfLoading"
          class="action-btn"
        />
      </div>
    </div>

    <!-- Copy Notification -->
    <div v-if="showCopied" class="copied-notification">
      Data Copied to Clipboard.
    </div>

    <!-- PDF Error -->
    <div v-if="pdfError" class="error-notification">
      {{ pdfError }}
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
  },
  experimentPkey: {
    type: [Number, String],
    default: null
  }
})

const emit = defineEmits(['close'])

const jsonTextarea = ref(null)
const showCopied = ref(false)
const selectedFormat = ref('json')
const pdfLoading = ref(false)
const pdfError = ref(null)

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
const handleSaveJson = () => {
  const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(jsonString.value)
  const downloadAnchor = document.createElement('a')
  downloadAnchor.setAttribute('href', dataStr)
  downloadAnchor.setAttribute('download', sanitizedFilename.value + '.json')
  downloadAnchor.click()
  emit('close')
}

// Download PDF
const handleDownloadPdf = async () => {
  if (!props.experimentPkey) {
    pdfError.value = 'Experiment ID is required for PDF export'
    setTimeout(() => { pdfError.value = null }, 3000)
    return
  }

  pdfLoading.value = true
  pdfError.value = null

  try {
    // Construct the PDF download URL
    const pdfUrl = `/newexperimental/api/download_pdf.php?id=${props.experimentPkey}`

    // Create a link and trigger download
    const link = document.createElement('a')
    link.href = pdfUrl
    link.download = sanitizedFilename.value + '.pdf'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)

    // Close modal after short delay
    setTimeout(() => {
      emit('close')
    }, 500)

  } catch (err) {
    console.error('PDF download error:', err)
    pdfError.value = 'Failed to download PDF. Please try again.'
    setTimeout(() => { pdfError.value = null }, 3000)
  } finally {
    pdfLoading.value = false
  }
}
</script>

<style scoped>
.format-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--p-surface-600);
}

.format-tab {
  padding: 0.75rem 2rem;
  background: transparent;
  border: none;
  color: var(--p-surface-400);
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
}

.format-tab:hover {
  color: var(--p-surface-200);
}

.format-tab.active {
  color: #dc3545;
}

.format-tab.active::after {
  content: '';
  position: absolute;
  bottom: -2px;
  left: 0;
  right: 0;
  height: 2px;
  background-color: #dc3545;
}

.json-fieldset,
.pdf-fieldset {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 1rem 1.25rem;
  margin: 0;
}

.json-fieldset legend,
.pdf-fieldset legend {
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

.pdf-info {
  display: flex;
  gap: 2rem;
  padding: 2rem 1rem;
  min-height: 350px;
}

.pdf-icon {
  color: #dc3545;
  flex-shrink: 0;
}

.pdf-description h3 {
  margin: 0 0 1rem 0;
  font-size: 1.25rem;
  color: var(--p-surface-100);
}

.pdf-description p {
  margin: 0 0 1rem 0;
  color: var(--p-surface-300);
  line-height: 1.6;
}

.pdf-description ul {
  margin: 0;
  padding-left: 1.5rem;
  color: var(--p-surface-400);
  line-height: 1.8;
}

.pdf-description li {
  margin-bottom: 0.25rem;
}

.action-btn {
  min-width: 120px;
  font-weight: 600;
}

.copied-notification,
.error-notification {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: white;
  padding: 2rem 3rem;
  font-size: 1.5rem;
  font-weight: 600;
  border-radius: 8px;
  z-index: 99999;
  animation: fadeOut 1.5s ease-in-out forwards;
}

.copied-notification {
  background-color: #f4511e;
}

.error-notification {
  background-color: #dc3545;
  animation: fadeOut 3s ease-in-out forwards;
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
